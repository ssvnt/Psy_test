"""Install the customer links on Laravel, verify via HTTPS, then send one correction."""
import fcntl
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile

HERE = Path(__file__).resolve().parent
FILES = {
    "ReadyMailAccess.php": "app/Services/GardnerCommerce/ReadyMailAccess.php",
    "ReadyMailController.php": "app/Http/Controllers/ReadyMailController.php",
    "booking.blade.php": "resources/views/gardner-mail-booking.blade.php",
    "routes.php": "routes/gardner_customer_mail.php",
}
HOOK = b"require __DIR__.'/gardner_customer_mail.php';"
ANCHOR = b"Route::view('/{any?}'"


def php(site, args, env=None):
    return subprocess.run(["php", *map(str, args)], cwd=site, check=True, stdout=subprocess.PIPE,
                          env=env).stdout.decode().strip()


def replace(path, data, model=None):
    with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as stream:
        tmp = Path(stream.name)
        try:
            if model is not None:
                os.fchown(stream.fileno(), model.st_uid, model.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(model.st_mode) if model else 0o644)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        except BaseException:
            tmp.unlink()
            raise
    try:
        os.replace(tmp, path)
    finally:
        tmp.unlink(missing_ok=True)


def apply(site, session):
    for name in (*FILES, "send.php"):
        php(site, ["-l", HERE/name])
    before = php(site, [HERE/"send.php", "plan", session])
    print(before, flush=True)
    if before == "ALREADY_ACCEPTED_BY_SMTP":
        return
    web = site/"routes/web.php"
    if web.is_symlink():
        raise ValueError("Unexpected route source symlink")
    old, meta = web.read_bytes(), web.stat()
    if old.count(ANCHOR) != 1 or old.count(HOOK) > 1:
        raise ValueError("Route source differs; no change applied")
    new = old if HOOK in old else old.replace(ANCHOR, HOOK+b"\n\n"+ANCHOR)
    cached = php(site, ["-r", """require 'vendor/autoload.php'; $a=require 'bootstrap/app.php';
        $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        echo $a->routesAreCached() ? $a->getCachedRoutesPath() : '';"""])
    cache = Path(cached) if cached else None
    if cache and (cache.is_symlink() or cache.resolve().parent != (site/"bootstrap/cache").resolve()):
        raise ValueError("Unexpected route cache path")
    old_cache = cache.read_bytes() if cache else None
    cache_meta = cache.stat() if cache else None
    added = []
    installed_cache = None
    backup = None
    receipt = site/"storage/app/dz-ready-mail-corrections-v1"/(session+".json")
    for name, target in FILES.items():
        path = site/target
        if path.is_symlink() or (path.parent.resolve() != path.parent) or (path.exists() and path.read_bytes() != (HERE/name).read_bytes()):
            raise ValueError("Existing source differs: "+target)
    try:
        if new != old:
            root = site/"storage/app/dz-ready-mail-backups"
            root.mkdir(mode=0o700, parents=True, exist_ok=True)
            backup = Path(tempfile.mkdtemp(dir=root))
            (backup/"web.php").write_bytes(old)
            if cache:
                (backup/"route-cache.php").write_bytes(old_cache)
        for name, target in FILES.items():
            path = site/target
            if not path.exists():
                replace(path, (HERE/name).read_bytes())
                added.append((path,name))
        if new != old:
            if web.read_bytes() != old:
                raise ValueError("Concurrent route edit")
            replace(web,new,meta)
        # An active route cache stays live while Laravel compiles the replacement privately.
        if cache:
            with tempfile.TemporaryDirectory(dir=cache.parent) as d:
                staged = Path(d)/"routes.php"
                php(site, ["artisan","route:cache","--no-interaction"], {**os.environ,"APP_ROUTES_CACHE":str(staged)})
                if cache.read_bytes() != old_cache:
                    raise ValueError("Concurrent route cache edit")
                installed_cache = staged.read_bytes()
                replace(cache,installed_cache,cache_meta)
        result = php(site,[HERE/"send.php","send",session])
        print(result, flush=True)
    except BaseException:
        # If SMTP may have accepted the message, the URLs must continue to work.
        if not receipt.exists():
            owned = web.read_bytes() == new and (not cache or cache.read_bytes() in (old_cache,installed_cache))
            if owned:
                if installed_cache is not None:
                    replace(cache,old_cache,cache_meta)
                if new != old:
                    replace(web,old,meta)
                    for path,name in added:
                        if path.is_file() and path.read_bytes() == (HERE/name).read_bytes():
                            path.unlink()
        raise


if __name__ == "__main__":
    if os.geteuid() != 0 or len(sys.argv) != 2 or not sys.argv[1].isascii() or not sys.argv[1].isdigit():
        sys.exit("Usage: root on application host: python3 apply.py SESSION_NUMBER")
    with open("/run/lock/dz-customer-ready-correction.lock","a") as guard:
        fcntl.flock(guard, fcntl.LOCK_EX)
        try:
            apply(Path("/var/www/bribon").resolve(), str(int(sys.argv[1])))
        except (OSError, ValueError, subprocess.CalledProcessError) as e:
            sys.exit("INSTALL_OR_DELIVERY_STOPPED: "+(str(e) if isinstance(e,ValueError) else type(e).__name__))

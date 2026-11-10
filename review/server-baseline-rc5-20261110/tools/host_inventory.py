"""Read-only host inventory for the Dobry Zhuk RC5 candidate (Linux, Python 3.8+)."""
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess


# Relative name, snapshot hash (- = absent from source), candidate source hash.
# "=" denotes the same tracked bytes. The Vite manifest compares the old build only.
# Build outputs are not part of the reconstructed source tree.
TARGETS = """
opt/dz-gardner-r27-live/core/server/send_customer.php 469890429ae6cec28412d25b8c657aabcc62d9ac831467ae52d484981a5e06aa 50cf7287c1f521af6bed1ae13519a3f78b5eb8775177b729b3b4427c9dd1114b
opt/dz-gardner-r27-live/core/server/stage_customer.py 724c19fb3019bb9a69ffffc657cdb2fad182a62945356ecc4fb830221d4fa922 a1bc627a51cb3264a5c13db92701732c01383b077df691ef3b60767626a287a2
opt/dz-gardner-r27-live/server/notify_generation_progress.php a53e619834aa5c03d69fdbbd4669671c1a3dc01800b153717debf56b9fafeffc 5d36abe349f6b6fe68b0f45ca0654c65c5f68fb8140022413c9f0c2e7d50a234
site/app/Console/Commands/GardnerCommerceTick.php 0e81497a1d4e30d725b909c25d790dd735d575e9b00af335932e99910478c209 f690b2b1529d22a3d20ee07625cba27409790d2a0644c4750705b94c558432c8
site/app/Http/Controllers/Api/GardnerCommerceController.php 5f01afe3e5685cb7712bc9d4a344f5c5eb905d536b3565355d5f880975d56704 b85e500b99388a77c16bd7eb35440095593e8811cac8a2418488c889db7778e1
site/app/Http/Controllers/Api/TeenTestController.php 24785269335852bcc8c42b7fe2b6b75d7ba0297fde3b5370f6bdfcb9aaf7432a 6ba2077e9c8c1fcf7309922eb6f0451199e306399756c700e79f82855a3787a8
site/app/Http/Controllers/Api/TestController.php a012ca230dca0026b6d8f5a90b9a2d6fa89547cc5e712035e30375880db1f0d5 3576ba587f7c444e788bea0fe25ddfc54fa500a945796ede41b6aa18878056d1
site/app/Http/Controllers/GardnerR27OwnerReviewController.php 784b48cb1a58460384a1174519db43dd7505fdaa4f27abce827bf686dc310e02 ad010339e6c16e3ff762e13d9f3ca068026d682f6cab89cd12a36bf15f38649e
site/app/Jobs/SendTestInterpretationPaymentEmailJob.php f428d429032618bb4aba7405a384fbdef839230e88fb7513a23feeababe85e7b 389cedfbe44e6c72a6e1aed0222f8af1564c9e5f9f2de2de4187f3a5d2d93ad3
site/app/Mail/GardnerFreeOfferMail.php - 5135f3a45364d092e10104ce964daa29aff8712a5cf6b01c06b3c95f371d9843
site/app/Services/AdminMaxNotificationService.php 0a57bcb0b25e6093ac3e2e3c49f8f901f972a257a6f94245c4cd401c34e7a433 219312aaeb51766e0ef00e1b1ee3fa72594e8eda4c43fd060165bfda2a7d94be
site/app/Services/Children/V2/GardnerV22/Production/GardnerV22MeteredProvider.php 56bd7c37601d47144ac1600a2a0b307470fb33ddb94c03a88d21196742d97f43 7383a1fd8bf36b484f65c6c3833a03d9d63c051335719a1316a6629b9de666c2
site/app/Services/GardnerCommerce/Consultations.php 15345355ef19d6954fdbfb495dac47a527e69afda47afdfaa76ba04c7e0c1097 86496e92c05b797cbaac17e1794717a9466322e992cd87567cf580dba769bae9
site/app/Services/GardnerCommerce/Orders.php 95c6ec9f4368ea88153ea72db3a31184afc9740bd2bb26b7fa5726dcbdd51850 82047447fd9c1fdd7608cfb35bed90cbe45955d0b0d4665e341c1acc97a57cec
site/app/Services/GardnerCommerce/Outbox.php 37ecf3bd25771b2aee501b7838c0cf73350da4c027c6660a46a92784c74d8314 d8f0cfd04b74b11ba5dd6aa89148867fe47517f8a81fb16856a1eef0463b1791
site/app/Services/GardnerCommerce/R27AccountLink.php - d1ad5a11d72d133d13833520548d37b6694122ae298f3a0873d90cc2898b46ed
site/app/Services/GardnerV22BrandedHtmlBuilder.php f7085bfb4401fe3c5e30186d3ce00c530448cb2a088f4a2072423c6defe31e6d 8779ca43a79e245bce3115aa7345edcc70b94c2c880fd8fe4de1dd72c65b8886
site/app/Services/GardnerV22PdfHtmlBuilder.php 190e9f09be9ec431767348b22fb54d873ab2b50524e4168c68a79af9c3117dd1 19e87bece4b3b15ae02002cf20a14df1a72231bc32155d3dc141eb2d384edb16
site/app/Services/MaxBotApiService.php 3cc1161da7a436cf901fc3a62367a6df178b76eb846f6144d865d36bb62126c0 00c929bac8344c6ff6cb5c7d48ea2c7a7853bc2ad3ce6fdf1ccbe28b15cc6efd
site/app/Services/Payments/TeenReportPrice.php - 49c741aeeeb5e6277333604989f77ef0e08e827a841ddf0aac9834ec8627ceec
site/app/Services/Payments/TochkaOperationGuard.php - 5b375b3145128dfe1ec3a0bafcea966e780d226adc4297f1c4204651b3fad255
site/config/gardner_commerce.php b95172085f4bbfe3c2fe0548dc9c99caf317510124733f85a914fdd0ab338de1 678dbf1c5003df825e9f5358d001a6bb06952d8cf319b6cc097accdf00478dba
site/public/examples/gardner-egor-v1/Egor-Report.html - eed50c8988404da16fbf9fb3361e6171a3b69c1b440c86728b54485a06e658ce
site/public/examples/gardner-egor-v1/Egor-Report.pdf - ae89385a7bc6290f921c4b7995916f01cceac15589580fc61b25205b40a2bc70
site/public/examples/teen-anna-v1/Anna-Report.html - ec0f71da4f66efd44538e84362b9ac2886334fe76b5ee2185a5506700774cac2
site/public/examples/teen-anna-v1/Anna-Report.pdf - c4581cc3bcb8c7895828cd864048c21b7499697dba087821e110b942925adc1f
site/resources/gardner/v2/text-quality/humanizer-ru/LICENSE - df96cb93e068e585693075a44606d98ddb3df5e92c42eb3fdf4881945a2a9f75
site/resources/gardner/v2/text-quality/pishi-normalno/LICENSE - f5292ce6b903b12c8f4e024c956eee7315268bb45ecd73f7812b5ea6bb5b8af1
site/resources/js/components/children/GardnerFreeResult.vue cfa8166bf25b3126c0b7290987121b396dd40d03bc3b5e983886d14c4cdb3fcc 994ee3fdf906fb96da15f5f44e0258c9ba4c518137311d093ebbb51ad4698f73
site/resources/js/content/children-report-example.json - b891caac64cce1549143d518150a750c9a71386fafb861236fc4995fb87d6ee4
site/resources/js/content/teen-report-example.json - 8f9add05d8c27989187523d5c44115feeb6b0cd618649c0f3582d857e561755e
site/resources/js/router.js d0ba2c9a3ab07a85ccc1cb81a827d2d4dafdd05d36f4bef92bbb3bc71cf5323b 4e707dfa0ca8800d87e8fc89f4d37c4b4765ad790ac9ea71fcc551ec699222f2
site/resources/js/views/ChildrenFreeExample.vue - 61d96f5914c3fb306e2f22b018c4bc1ec729b4b40dc0d238e6d7f813ed56123f
site/resources/js/views/ChildrenLandingPage.vue 8909a40b2661d64736af8d5ff1e6dc51680c87f19f2a7cf30e1d94449a720097 0ac0eb09a75ae35c24b166d5ba48563c91648438236ba7faec7532f4b42b1373
site/resources/js/views/GardnerAccountPage.vue 07d8937811767b1751533a8e68fd79cdde2ffe97fcf571df288040299c886867 f9231be23884c7df1eb33473771d3e0bdf54b804bc40959161d79ca1d94b495f
site/resources/js/views/ReportExample.vue - 64c5e5e1be53b924d4a85ced1066321a8877fc933ea37d117c571e893a8d3754
site/resources/js/views/ResultPage.vue 5fb42e0d27629fd40909887bfa352072737f85890d4873a92d270d133726ca13 66ff80b8659959268aad79feab0153575f7ad45d2f1345326210c273c5d37419
site/resources/js/views/TeenFreeExample.vue 532529347f80339ec282ebd9fd4259b0f103dcf319b0a8464ba110d0a04db856 0574758076a436036ffcb4d99edd51804c04b3b1385e76206bd3e0943e7dfaa2
site/resources/views/emails/gardner-free-offer-text.blade.php - b9a3d8faa8d6e74d1d3b237ac332c9789ad6e9bac9206f94cd094b59b6037a44
site/resources/views/emails/gardner-free-offer.blade.php - ff40aaa6220df5388f0d75eb98fb88f5bf81897ebccd1791030c7d153448ceaf
site/tests/Feature/GardnerCommerceTest.php bedba5553e3e29589fb834c99a7c2033c9bd50796f267b330f1cbe11409270e8 defef44e3661af1cb6d4c6b73bb2632232dcaad014b37ec4139c8349851ce331
site/tests/Feature/GardnerServerIntegrationTest.php ba7adf2d33fc76a4fbbedb3ddf184e16d20b1c7e7fe5594f482fc18569008ec9 3005e85afdbbb99629da6af59b1dbb61ac70217092ba97cc5d540894f8dc1dc1
site/tests/Feature/GardnerV22PaidFlowTest.php 320232f99211e67e3fdc91ba6902bd058ae977fbea989bd2ba8dec53a57548e8 84669ae076bb8ad99054280e507774a37dbbdefcbc8f23912e3d5833d2908c83
site/tests/Feature/ProductPromoPricingTest.php b2b84c472fc0677c60aabf8e5d4584860ec57cc1308ff9a72f9044890361bd68 01f59d76d9215f963001c9a1acfa639a4419035e5faff6f696bc53e83ee85287
site/tests/Support/GardnerCommerceReady.php 192b917d28762179af03dee3b37bcfacc345c23cffc1b0b228c57b9bd3370204 6cb8954f7b28d032a5149e3400a4344cfc05b4558e31925bada2f530d8a2c7c9
site/tools/gardner/run-a08-k3.ps1 - 0989eebc61a3dda879700d98eac8cf7aac99b7c7f850677a1a96a8e150513610
opt/dz-gardner-r27-live/server/queue_worker.php 94a6e413446a7e82722de1db0bfb9bd762a8a82a40a3d01327b23a08e7da5137 =
opt/dz-gardner-r27-live/server/bootstrap.php 8b17dd2d195747429e662aaa5c650c1c6241ce120b854ac4aa104fc70166c0f1 =
site/config/queue.php c98103ef8ab08b87648c50c4ae16c21bcb861b93f1690e38b2c0971f59a500f4 =
site/config/gardner_report_v22.php 1ea7dc0c61b140ce82b8dfa40342d96274fdafb746208f94cd35e17b712c277b =
site/composer.lock 75b0737e6f8e211015fe9bef81fe70208399333fa54dd348514aa4dd6f0fe74f =
site/package-lock.json 6a3e9f2549b0b34f089dab519610d1577632db108722481e807536f1a1671ea0 =
site/public/build/manifest.json 1f89ef26e5e64f8d7208ab07cf92ae389e852a882ba085bf531ad9ead26c2c84 baseline-only
site/resources/js/views/TeenLandingPage.vue 73c8cb20d40a14182a7905050cfa82dcece6afaccb451ad2ae9b304274ea65fd =
site/app/Services/Children/V2/GardnerV22/Production/GardnerV22DeepSeekHttpTransport.php 01aca446e1229234a4e389cf0816b827691bcfa9290d0dcbd1bffc514bf9f21e =
"""

SITE = Path("/var/www/bribon")
R27 = Path("/opt/dz-gardner-r27-live")
WORKERS = ("bribon-gardner-v22-queue", "bribon-gardner-v22", "dz-gardner-r27-queue")
PERIODIC = (
    "dz-gardner-commerce", "dz-gardner-r27-delivery", "dz-gardner-r27-finalize",
    "dz-gardner-r27-max-progress", "dz-gardner-r27-one-retry", "dz-gardner-r27-paid",
    "dz-r27-customer-delivery", "dz-r27-live-paid", "dz-r27-universal-owner-gate",
)
OPTIONAL_CLI = ("run-gardner-v2-1-real-max-gate.php", "inspect-gardner-v2-sse-forensics.php",
                "export-latest-completed-gardner-v2-input.php")
QUEUE_NAMES = {"gardner-r27", "gardner-v22", "default", "reports"}
CONNECTIONS = {"gardner_v22", "redis_gardner_v22", "redis", "database", "sync"}


def file_hash(path, root):
    # Refuse symlinks and non-regular files, including pipes and device nodes.
    current = root
    try:
        if root.is_symlink():
            return "symlink"
        for part in path.relative_to(root).parts:
            current = current / part
            if current.is_symlink():
                return "symlink"
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(fd, "rb") as stream:
            info = os.fstat(stream.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size > 16 * 1024 * 1024:
                return "not_checked"
            h = hashlib.sha256()
            for chunk in iter(lambda: stream.read(65536), b""):
                h.update(chunk)
            return h.hexdigest()
    except FileNotFoundError:
        return "absent"
    except OSError:
        return "unreadable"


def compare_sources(site, r27):
    result = {}
    for row in TARGETS.splitlines():
        if not row:
            continue
        name, old, new = row.split()
        if name.startswith("site/"):
            root, rel = site, name[len("site/"):]
        else:
            root, rel = r27, name[len("opt/dz-gardner-r27-live/"):]
        actual = file_hash(root / rel, root)
        if actual == old:
            status = "snapshot"
        elif actual == new:
            status = "candidate"
        elif actual == "absent":
            status = "absent_addition" if old == "-" else "missing_snapshot_file"
        elif len(actual) == 64:
            status = "other"
        else:
            status = actual
        result[name] = status
    return result


def systemctl(*args):
    try:
        proc = subprocess.run(["systemctl", *args], capture_output=True, text=True, timeout=5)
    except (OSError, subprocess.SubprocessError):
        return None
    return proc.stdout if proc.returncode == 0 else None


def properties(unit, keys):
    output = systemctl("show", "--no-pager", "--property=" + ",".join(keys), unit)
    if output is None:
        return {"unit": unit, "inspection": "unavailable"}
    return dict(unit=unit, **dict(line.split("=", 1) for line in output.splitlines()
                                  if line.partition("=")[0] in keys))


def worker_arguments(args):
    # Return only known queue/connection labels, never a raw process command line.
    if "/opt/dz-gardner-r27-live/server/queue_worker.php" in args:
        return {"program": "r27_php_wrapper"}
    for idx, arg in enumerate(args[:-1]):
        if Path(arg).name != "artisan" or args[idx + 1] not in ("queue:work", "queue:listen"):
            continue
        rest = args[idx + 2:]
        result = {"program": args[idx + 1], "queue": "from_config", "connection": "from_config"}
        if rest and not rest[0].startswith("-"):
            result["connection"] = rest[0] if rest[0] in CONNECTIONS else "custom"
        for i, token in enumerate(rest):
            if token == "--queue":
                value = rest[i + 1] if i + 1 < len(rest) else ""
            elif token.startswith("--queue="):
                value = token.partition("=")[2]
            else:
                continue
            result["queue"] = ",".join(v if v in QUEUE_NAMES else "custom" for v in value.split(","))
            break
        return result
    return None


def worker_processes(pid):
    pending = [pid] if pid.isdecimal() and int(pid) > 0 else []
    seen, result = set(), []
    while pending and len(seen) < 16:
        current = pending.pop()
        if current in seen:
            continue
        seen.add(current)
        proc = Path("/proc") / current
        try:
            args = (proc / "cmdline").read_bytes().decode(errors="replace").rstrip("\0").split("\0")
            details = worker_arguments(args)
            if details:
                result.append(details)
            children = (proc / "task" / current / "children").read_text().split()
            pending.extend(p for p in children if p.isdecimal() and int(p) > 0)
        except OSError:
            continue
    return result


def main():
    if not (SITE / "artisan").is_file():
        raise SystemExit("Laravel root not found")
    statuses = compare_sources(SITE, R27)
    totals = {s: list(statuses.values()).count(s) for s in sorted(set(statuses.values()))}
    details = {name: status for name, status in statuses.items()
               if status not in ("snapshot", "absent_addition")}
    # Optional QA scripts: presence only, no access to private operational data.
    cli = {name: (SITE / "scripts" / name).is_file() for name in OPTIONAL_CLI}
    print(json.dumps({"checked": len(statuses), "counts": totals, "details": details,
                      "optional_php_files": cli}, sort_keys=True))
    for name in WORKERS:
        data = properties(name + ".service", ("LoadState", "ActiveState", "MainPID", "NRestarts"))
        data["recognized_processes"] = worker_processes(data.get("MainPID", ""))
        print(json.dumps(data, sort_keys=True))
    for name in PERIODIC:
        timer = properties(name + ".timer", ("ActiveState", "SubState", "LastTriggerUSec"))
        svc = properties(name + ".service", ("ActiveState", "Result", "ExecMainStatus", "ExecMainExitTimestamp"))
        print(json.dumps({"timer": timer, "service": svc}, sort_keys=True))
    failed = systemctl("list-units", "--type=service", "--state=failed", "--plain", "--no-legend",
                       "--no-pager", "dz-r27-*", "dz-gardner-*", "bribon-gardner-*")
    if failed is None:
        print(json.dumps({"failed_services": "inspection_unavailable"}))
    else:
        names = [line.split()[0] for line in failed.splitlines() if line.split()]
        for name in names:
            print(json.dumps(properties(name, ("ActiveState", "Result", "ExecMainCode", "ExecMainStatus",
                                               "ExecMainExitTimestamp", "NRestarts", "TriggeredBy")), sort_keys=True))
        print(json.dumps({"failed_services_count": len(names)}))


if __name__ == "__main__":
    main()

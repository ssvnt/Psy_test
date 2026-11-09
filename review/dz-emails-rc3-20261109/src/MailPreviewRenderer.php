<?php

declare(strict_types=1);
namespace DobryZhuk\EmailRC3;

/** No SMTP side effects. Preview-only renderer; use Laravel's tested Blade Mailable after review. */
final class MailPreviewRenderer
{
    public static function render(array $template, array $urls, string $expectedHost): array
    {
        if (!isset($template['subject'], $template['title'], $template['paragraphs'], $template['buttons'], $template['footer'])
            || !is_array($template['paragraphs']) || !is_array($template['buttons']) || count($template['buttons']) < 1) {
            throw new \InvalidArgumentException('TEMPLATE_SCHEMA_INVALID');
        }
        $esc=static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $str=static function(mixed $v): string {
            if (!is_string($v) || trim($v)==='' || strlen($v) > 16000) throw new \InvalidArgumentException('TEMPLATE_TEXT_INVALID');
            return $v;
        };
        $subject=$str($template['subject']);$title=$str($template['title']);$footer=$str($template['footer']);
        $paras=array_map($str,$template['paragraphs']);
        if (count($paras) > 15) throw new \InvalidArgumentException('TOO_MANY_PARAGRAPHS');
        $html='<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $html.='<title>'.$esc($subject).'</title></head><body style="font:16px/1.6 Arial,sans-serif;background:#f8f5ee;color:#303b2e;padding:24px">';
        $html.='<main style="background:#fff;max-width:640px;margin:auto;padding:28px;border-radius:24px">';
        $html.='<p style="color:#4f754f;font-weight:bold">ДОБРЫЙ ЖУК</p><h1>'.$esc($title).'</h1>';
        $plain=$title."\n\n";
        foreach($paras as $paragraph) {$html.='<p>'.$esc($paragraph).'</p>';$plain.=$paragraph."\n\n";}
        $keys=[];
        foreach($template['buttons'] as $b) {
            if (!is_array($b) || !isset($b['key'], $b['label'])) throw new \InvalidArgumentException('BUTTON_INVALID');
            $key=$str($b['key']);$label=$str($b['label']);
            if (!in_array($key,['account_url','report_url','consultation_url'],true) || isset($keys[$key])) throw new \InvalidArgumentException('BUTTON_KEY_INVALID');
            $keys[$key]=true;
            $url=$urls[$key]??null;
            self::assertSameHostHttps($url,$expectedHost);
            $html.='<p><a style="display:inline-block;border-radius:20px;padding:12px 20px;background:#4f754f;color:white;text-decoration:none" href="'.$esc($url).'">'.$esc($label).'</a></p>';
            $plain.=$label.': '.$url."\n";
        }
        if (str_contains($footer,'{{unsubscribe_url}}')) {
            $u=$urls['unsubscribe_url']??null;
            self::assertSameHostHttps($u,$expectedHost);
            $footer=str_replace('{{unsubscribe_url}}',$u,$footer);
        }
        $html.='<p style="font-size:12px;color:#61725f">'.$esc($footer).'</p></main></body></html>';
        $plain.="\n".$footer."\n";
        return ['subject'=>$subject,'html'=>$html,'text'=>$plain];
    }

    private static function assertSameHostHttps(mixed $url, string $host): void
    {
        if (!is_string($url)||strlen($url)>2048 || preg_match('/[\r\n\x00-\x1F]/',$url)) throw new \InvalidArgumentException('UNSAFE_LINK');
        $parts=parse_url($url);
        if (!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
            || strtolower((string)($parts['host']??''))!==strtolower($host)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('UNSAFE_LINK');
        }
    }
}

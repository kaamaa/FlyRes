<?php

namespace App\Twig;

use App\Tools\HtmlSanitizer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig-Filter fuer die Ausgabe von Pinnwand-Notizen:
 *   {{ n.text|note_html }}  -> bereinigtes, sicheres HTML (Whitelist).
 *   {{ n.text|note_text }}  -> Nur-Text-Fassung (fuer Kurzvorschauen).
 * Die Bereinigung bei der Ausgabe schuetzt auch alte/rohe Inhalte.
 */
class NoteExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('note_html', [$this, 'noteHtml'], ['is_safe' => ['html']]),
            new TwigFilter('note_text', [$this, 'noteText']),
        ];
    }

    public function noteHtml(?string $value): string
    {
        $v = (string) $value;
        // Alt-Notizen (reiner Text ohne Tags): Zeilenumbrueche erhalten, escaped.
        if (strpos($v, '<') === false) {
            return nl2br(htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
        }
        return HtmlSanitizer::clean($v);
    }

    public function noteText(?string $value): string
    {
        return HtmlSanitizer::toText($value);
    }
}

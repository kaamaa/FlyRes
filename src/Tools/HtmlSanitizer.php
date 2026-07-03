<?php

namespace App\Tools;

/**
 * Schlanker Whitelist-HTML-Sanitizer (ohne externe Bibliothek, nur DOMDocument)
 * fuer die Pinnwand-Notizen (WYSIWYG-Eingabe). Erlaubt nur eine kleine, sichere
 * Menge an Formatierungs-Tags und -Attributen; alles andere (script, style,
 * on*-Handler, javascript:-Links, fremde Attribute) wird entfernt. Wird sowohl
 * beim Speichern als auch bei der Ausgabe angewandt (idempotent), damit auch
 * alte oder manipulierte Inhalte sicher dargestellt werden.
 */
class HtmlSanitizer
{
    /** Erlaubte Tags (alles andere wird "entpackt": Inhalt bleibt, Tag faellt weg). */
    private const ALLOWED = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike',
        'ul', 'ol', 'li', 'a', 'h3', 'h4', 'h5', 'mark', 'span', 'blockquote', 'div',
    ];

    /** Gefaehrliche Tags: komplett entfernen (samt Inhalt). */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'textarea',
        'button', 'select', 'option', 'svg', 'math', 'link', 'meta', 'base',
        'noscript', 'template', 'title', 'head', 'audio', 'video', 'source',
    ];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // <meta charset> erzwingt UTF-8; <body>-Wrapper macht die Serialisierung einfach.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><meta charset="utf-8"><body>' . $html . '</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = null;
        foreach ($doc->childNodes as $n) {
            if ($n instanceof \DOMElement && strtolower($n->nodeName) === 'body') { $body = $n; break; }
        }
        if ($body === null) {
            return '';
        }

        self::cleanChildren($body);

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /** Nur-Text-Fassung (fuer Kurzvorschauen), sicher escaped im Template. */
    public static function toText(?string $html): string
    {
        $t = preg_replace('#<(br|/p|/div|/li|/h[1-6])\s*/?>#i', "$0\n", (string) $html);
        return trim(html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function cleanChildren(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                continue;                                   // Text bleibt (wird beim Serialisieren escaped)
            }
            if (!$child instanceof \DOMElement) {
                $node->removeChild($child);                 // Kommentare, PIs etc. raus
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);                 // gefaehrliches Tag samt Inhalt entfernen
                continue;
            }
            if (!in_array($tag, self::ALLOWED, true)) {
                self::cleanChildren($child);                // Teilbaum zuerst saeubern
                while ($child->firstChild) {                // dann Element entpacken
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            self::stripAttributes($child, $tag);
            self::cleanChildren($child);
        }
    }

    private static function stripAttributes(\DOMElement $el, string $tag): void
    {
        $keep = [];
        if ($tag === 'a') {
            $href = trim($el->getAttribute('href'));
            if (self::safeUrl($href)) {
                $keep['href']   = $href;
                $keep['rel']    = 'noopener noreferrer nofollow';
                $keep['target'] = '_blank';
            }
        } elseif ($tag === 'span' || $tag === 'mark') {
            $style = self::safeStyle($el->getAttribute('style'));
            if ($style !== '') {
                $keep['style'] = $style;
            }
        }
        // Alle vorhandenen Attribute entfernen ...
        foreach (iterator_to_array($el->attributes) as $attr) {
            $el->removeAttribute($attr->nodeName);
        }
        // ... und nur die erlaubten wieder setzen.
        foreach ($keep as $k => $v) {
            $el->setAttribute($k, $v);
        }
    }

    private static function safeUrl(string $u): bool
    {
        if ($u === '') {
            return false;
        }
        if (preg_match('#^(https?:)?//#i', $u) || preg_match('#^https?:#i', $u) || preg_match('#^mailto:#i', $u)) {
            return true;
        }
        if (preg_match('~^[/#]~', $u)) {
            return true;                                    // relativ / Anker
        }
        // bare relativer Pfad ohne Schema (kein "javascript:", "data:" o.ae.)
        return (bool) preg_match('#^[a-z0-9._~\-/?&=%]+$#i', $u);
    }

    private static function safeStyle(string $style): string
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (strpos($decl, ':') === false) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            if (!in_array($prop, ['color', 'background-color'], true)) {
                continue;
            }
            if (preg_match('/^#[0-9a-f]{3,8}$/i', $val)
                || preg_match('/^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/i', $val)
                || preg_match('/^[a-z]{3,20}$/i', $val)) {
                $out[] = $prop . ':' . $val;
            }
        }
        return implode(';', $out);
    }
}

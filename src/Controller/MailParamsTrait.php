<?php

namespace App\Controller;

/**
 * Baut die Standard-Parameter fuer Info-Mails (Programm-Version, Absender,
 * Herkunft Web/Mobile). Ersetzt die zuvor in mehreren Controllern kopierten
 * getParameter()-Zeilen. Wird per `use MailParamsTrait;` eingebunden.
 */
trait MailParamsTrait
{
    /** @return array{program_version:string, mail_from:string, source:string} */
    protected function mailParams(string $source = 'web'): array
    {
        return [
            'program_version' => $this->getParameter('program_version'),
            'mail_from'       => $this->getParameter('mail_from'),
            'source'          => $source,
        ];
    }
}

<?php

namespace App\EventSubscriber;

use App\Service\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Protokolliert verweigerte Zugriffe (AccessDeniedException = angemeldeter Nutzer
 * ohne ausreichende Rolle) in den Audit-Kanal. Nicht-angemeldete Zugriffe auf
 * geschuetzte Seiten laufen ueber den Login-Redirect (start()) und erzeugen KEINE
 * AccessDeniedException – hier landet also gezielt der "eingeloggt, aber verboten"-Fall.
 */
class AuditAccessDeniedSubscriber implements EventSubscriberInterface
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onException'];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof AccessDeniedException) {
            return;
        }
        $req = $event->getRequest();
        $this->audit->log('access.denied', [
            'route'  => $req->attributes->get('_route'),
            'path'   => $req->getPathInfo(),
            'method' => $req->getMethod(),
        ]);
    }
}

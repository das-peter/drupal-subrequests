<?php


namespace Drupal\subrequests\EventSubscriber;

use Drupal\subrequests\Blueprint\RequestTree;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SubresponseSubscriber implements EventSubscriberInterface {

  /**
   * Marks the request as done.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The event to process.
   */
  public function onResponse(FilterResponseEvent $event) {
    $request = $event->getRequest();
    $request->attributes->set(RequestTree::SUBREQUEST_DONE, TRUE);
    // Carry over the Content ID header from the request to the response.
    $header_name = 'Content-ID';
    $event->getResponse()->headers->set(
      $header_name,
      $request->headers->get($header_name)
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Run shortly before \Drupal\Core\EventSubscriber\FinishResponseSubscriber.
    $events[KernelEvents::RESPONSE][] = ['onResponse'];
    return $events;
  }

}

<?php
namespace Drupal\my_module\EventSubscriber;

class MySubscriber implements \Symfony\Component\EventDispatcher\EventSubscriberInterface {
    public static function getSubscribedEvents() {
        return [
            'kernel.request' => 'onKernelRequest',
            \Drupal\Core\Config\ConfigEvents::SAVE => 'onConfigSave',
        ];
    }
}

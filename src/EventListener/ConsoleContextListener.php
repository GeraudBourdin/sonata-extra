<?php
    
    namespace Partitech\SonataExtra\EventListener;
    
    use Sonata\PageBundle\Model\SiteManagerInterface;
    use Sonata\PageBundle\Request\RequestFactory;
    use Symfony\Component\EventDispatcher\EventSubscriberInterface;
    use Symfony\Component\HttpFoundation\RequestStack;
    use Symfony\Component\Console\Event\ConsoleCommandEvent;
    use Symfony\Component\EventDispatcher\EventDispatcherInterface;
    use Symfony\Component\HttpKernel\Event\RequestEvent;
    use Symfony\Component\HttpKernel\HttpKernelInterface;
    use Symfony\Component\HttpKernel\KernelEvents;
    
    class ConsoleContextListener implements EventSubscriberInterface
    {
        
        public function __construct(
            private readonly RequestStack         $requestStack,
            private readonly SiteManagerInterface $siteManager,
            private readonly EventDispatcherInterface $eventDispatcher
        )
        {}
        
        public function onConsoleCommand(ConsoleCommandEvent $event): void
        {
            $siteUrl = getenv('SYMFONY_HTTP_CONTEXT_URL');
            
            if (!$siteUrl) {
                $site = $this->siteManager->findOneBy(['isDefault'=> true]);
                if(empty($site)){
                    echo "\nDefault sonata page site is not yet configured\n";
                    return;
                }
                $siteUrl = 'https://'.$site->getHost().$site->getRelativePath();
            }
            
            $parsedUrl = parse_url($siteUrl);
            $_SERVER['REQUEST_URI'] = $parsedUrl['path'] ?? '/';
            $_SERVER['HTTP_HOST'] = $parsedUrl['host'] ?? 'localhost';
            
            if (isset($parsedUrl['scheme']) && $parsedUrl['scheme'] === 'https') {
                $_SERVER['HTTPS'] = 'on';
            }
            
            if (!empty($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $_GET);
            }
            
            $request = RequestFactory::createFromGlobals('host_with_path_by_locale');
            $request->attributes->set('_controller','none');

            $kernel = $event->getCommand()->getApplication()->getKernel();
            $requestEvent = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
            $this->eventDispatcher->dispatch($requestEvent, KernelEvents::REQUEST);

            $this->requestStack->push($request);
            
        }
        
        public static function getSubscribedEvents(): array
        {
            return [
                ConsoleCommandEvent::class => 'onConsoleCommand',
            ];
        }
    }
<?php

namespace Partitech\SonataExtra\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Partitech\SonataExtra\Service\TranslateObjectService;
use Sonata\AdminBundle\Admin\Pool;
use Partitech\SonataExtra\Controller\Admin\PageAdminController;

#[AsCommand(
    name: 'sonata:extra:translate-content',
    description: 'Translate your content into a given site locale',
)]
class TranslateContentCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private ParameterBagInterface $parameterBag;
    private Pool $adminPool;
    private TranslateObjectService $translateObjectService;
    private PageAdminController $pageAdminController;

    #[Required]
    public function autowireDependencies(
        EntityManagerInterface $entityManager,
        ParameterBagInterface  $parameterBag,
        TranslateObjectService $translateObjectService,
        Pool                   $adminPool,
        PageAdminController    $pageAdminController
    ): void
    {
        $this->entityManager = $entityManager;
        $this->parameterBag = $parameterBag;
        $this->translateObjectService = $translateObjectService;
        $this->adminPool = $adminPool;
        $this->pageAdminController = $pageAdminController;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'help',
                'h',
                InputOption::VALUE_NONE,
                'Display this help message.'
            )
            ->addOption(
                'site',
                null,
                InputOption::VALUE_REQUIRED,
                'Specify the site ID(s) for which to translate content.'
            )
            ->addOption(
                'reference-site',
                null,
                InputOption::VALUE_REQUIRED,
                'Specify the reference site ID for translation.'
            )
            ->addOption(
                'service',
                null,
                InputOption::VALUE_REQUIRED,
                'Specify the entity to translate by its admin service.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);


        $help = $input->getOption('help');
        $site = $input->getOption('site');
        $service = $input->getOption('service');
        $referenceSite = $input->getOption('reference-site');
        if ($help) {
            $io->success('Usage: bin/console sonata:extra:translate-content --site=1,2,3 --reference-site=1  --service=Partitech\SonataExtra\Entity\Article');
            return Command::SUCCESS;
        }


        if (!$site || !$referenceSite || !$service) {
            $io->error('Both --service --site and --reference-site options are required. Usage: bin/console sonata:extra:translate-content --site=1,2,3 --reference-site=1 --service=Partitech\SonataExtra\Admin\ArticleAdmin');
            return Command::INVALID;
        }

        $adminService = $this->adminPool->getAdminByAdminCode($service);
        $entityClass = $adminService->getClass();

        $siteClass = $this->parameterBag->get('sonata.page.site.class');
        $siteRepository = $this->entityManager->getRepository($siteClass);
        $sites = $siteRepository->findAll();
        $siteLocales = [];
        foreach ($sites as $s) {
            $siteLocales[$s->getId()] = $s->getLocale();
        }

        if (empty($siteLocales[$referenceSite])) {
            $io->error('Reference site does not exist.');
            return Command::INVALID;
        }
        $site_list = explode(',', $site);
        $key = array_search($referenceSite, $site_list);
        if ($key !== false) {
            unset($site_list[$key]);
        }

        foreach ($site_list as $s) {
            if (empty($siteLocales[$s])) {
                $io->error('Site ' . $s . ' does not exist.');
                return Command::INVALID;
            }
        }


        $fqcnRepository = $this->entityManager->getRepository($entityClass);
        $fqcnList = $fqcnRepository->createQueryBuilder('e')
            ->andWhere('e.site = :val')
            ->setParameter('val', $referenceSite)
            ->getQuery()
            ->getResult();


        $progressBar = new ProgressBar($output, 100);
        $format = "\n\t\t<fg=white;bg=cyan> %status:-45s%</>\n\n";
        $format .= "\t\t[%bar%] %percent:3s%%\n\n";
        $format .= "\t\t%current_item%\n";
        foreach ($site_list as $site_id) {
            $format .= "\t\t" . $siteLocales[$site_id] . " : %current_job_" . $siteLocales[$site_id] . "% \n";
        }
        $format .= "\t\t\n";
        $progressBar->setFormat($format);
        $progressBar->setBarCharacter('<fg=green>⚬</>');
        $progressBar->setEmptyBarCharacter("<fg=red>⚬</>");
        $progressBar->setProgressCharacter("<fg=green>𝄞</>");

        $progressBar->setProgress(0);
        $progressBar->setMessage('Initialisation', 'status');
        //$progressBar->setMessage('waiting', 'current_job');
        $progressBar->start();


        $jobs = 0;
        $total_job = count($fqcnList) * count($site_list);
        foreach ($fqcnList as $item) {

            $progressBar->setMessage('<fg=green>#' . $item->getId() . ' : ' . $item . '</>', 'current_item');
            foreach ($site_list as $site_id) {
                $progressBar->setMessage('', "current_job_" . $siteLocales[$site_id]);
            }
            $progressBar->display();
            foreach ($site_list as $site_id) {

                $progress_percent = round((100 / $total_job) * $jobs);
                $progressBar->setMessage('<fg=green>' . $jobs . ' / ' . $total_job . ' tasks</> ', 'status');

                $progressBar->setProgress($progress_percent);
                $progressBar->display();


                if (!empty($item->translations[$site_id]['entity_id'])) {
                    $progressBar->setMessage('<fg=red>exist</>', "current_job_" . $siteLocales[$site_id]);
                } else {
                    $progressBar->setMessage($siteLocales[$site_id], "current_job_" . $siteLocales[$site_id]);
                    $progressBar->display();
                    if ($service == "sonata.page.admin.page") {
                        $this->pageAdminController->createPageFromLocaleAction($item->getId(), $referenceSite, $site_id);
                    } else {
                        $this->translateObjectService->createTranslation($item->getId(), $referenceSite, $site_id, $service);
                    }
                    $progressBar->setMessage($siteLocales[$site_id] . '<info>✓</info>', "current_job_" . $siteLocales[$site_id]);
                }
                $jobs++;
            }
        }

        $progressBar->finish();

        $io->success('Article have successfully been translated');
        return Command::SUCCESS;
    }
}
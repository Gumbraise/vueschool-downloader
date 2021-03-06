<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;

class DownloaderService
{
    private const BAD_WINDOWS_PATH_CHARS = ['<', '>', ':', '"', '/', '\\', '|', '?', '*'];

    /** @var SymfonyStyle $io */
    private $io;

    /** @var array $configs */
    private $configs;

    /** @var Client $client */
    private $client;

    /**
     * @param SymfonyStyle $io
     * @param array $configs
     */
    public function __construct(SymfonyStyle $io, array $configs)
    {
        $this->io = $io;
        $this->configs = $configs;
        $this->client = new Client([
            'base_uri' => $this->configs['URL'],
            'cookies' => true
        ]);
    }

    /**
     * @return void
     */
    public function download(): void
    {
        $this->login();

        $downloadPath = "{$this->configs['TARGET']}/vueschool";
        if (!is_dir($downloadPath) && !mkdir($downloadPath) && !is_dir($downloadPath)) {
            $this->io->error("Unable to create download directory '{$downloadPath}'");

            return;
        }

        $courses = $this->getCourses();

        $this->io->section('Wanted courses');
        $this->io->listing(array_keys($courses));

        $coursesCounter = 0;
        $coursesCount = \count($courses);
        foreach ($courses as $title => $urls) {
            ++$coursesCounter;
            $this->io->newLine(3);
            $this->io->title("Processing course: '{$title}' ({$coursesCounter} of {$coursesCount})");
            $isCodeDownloaded = false;
            $isScriptDownloaded = false;

            if (empty($urls)) {
                $this->io->warning('No chapters to download');

                continue;
            }

            $titlePath = str_replace(self::BAD_WINDOWS_PATH_CHARS, '-', $title);
            $journalName = preg_replace('/\s+/', '_', $titlePath);
            $coursePath = "{$downloadPath}/{$journalName}";

            if (!is_dir($coursePath) && !mkdir($coursePath) && !is_dir($coursePath)) {
                $this->io->error('Unable to create course directory');

                continue;
            }

            $chaptersCounter = 0;

            foreach ($urls as $name => $url) {
                if (preg_match("/\/activity\/[0-9]{3}$/", $url)) {
                    unset($urls[$name]);
                }
            }

            $chaptersCount = \count($urls);
            foreach ($urls as $name => $url) {
                ++$chaptersCounter;
                $this->io->newLine();
                $this->io->section("Chapter '{$this->dashesToTitle($name)}' ({$chaptersCounter} of {$chaptersCount})");

                try {
                    $response = $this->client->get($url);
                } catch (ClientException $e) {
                    $this->io->error($e->getMessage());

                    continue;
                }

                $crawler = new Crawler($response->getBody()->getContents());

                foreach ($crawler->filter('div.text-blue-darkest div.text-blue-darkest > a') as $i => $a) {
                    $url = $a->getAttribute('title');
                    $url_re = preg_replace('/\s+/', '_', $url);
                    $fileName = false;

                    $mdContent = $crawler->filter('div.flex-no-grow');
                    $content = "# {$mdContent->filter('h1.font-normal')->html()}<br>{$mdContent->filter('div.text')->html()}";
                    $person = sprintf('%03d', $chaptersCounter) . "-{$name}.md";;

                    if (!file_exists("{$coursePath}/{$person}")) {
                        file_put_contents("{$coursePath}/{$person}", $content);
                    } else {
                        $this->io->writeln("File '{$person}' was already downloaded");
                        continue;
                    }

                    $fileName = sprintf('%03d', $chaptersCounter) . "-{$name}.mp4";

                    if ($fileName === null) {
                        continue;
                    }

                    if (!$fileName) {
                        $this->io->warning('Unable to get download links');
                        continue;
                    }

                    if (file_exists("{$coursePath}/{$fileName}")) {
                        $this->io->writeln("File '{$fileName}' was already downloaded");
                        continue;
                    }

                    $this->downloadFile($a->getAttribute('href'), $coursePath, $fileName);
                    $this->io->newLine();
                }
            }
        }

        $this->io->success('Finished');
    }

    /**
     * @param string $url
     * @param string $filePath
     * @param string $fileName
     *
     * @return void
     */
    private function downloadFile($url, $filePath, $fileName): void
    {
        $io = $this->io;
        $progressBar = null;
        $file = "{$filePath}/{$fileName}";
        try {
            $this->client->get($url, [
                'save_to' => $file,
                'allow_redirects' => ['max' => 2],
                'progress' => function ($total, $downloaded) use ($io, $fileName, &$progressBar) {
                    if ($total && $progressBar === null) {
                        $progressBar = $io->createProgressBar($total);
                        $progressBar->setFormat("<info>[%bar%]</info> {$fileName}");
                        $progressBar->start();
                    }

                    if ($progressBar !== null) {
                        if ($total === $downloaded) {
                            $progressBar->finish();

                            return;
                        }

                        $progressBar->setProgress($downloaded);
                    }
                }
            ]);
        } catch (\Exception $e) {
            $this->io->warning($e->getMessage());

            unlink($file);
        }
    }

    /**
     * @return array
     */
    private function getCourses(): array
    {
        $courses = $this->fetchCourses();
        $whitelist = $this->configs['COURSES'];

        if (!empty($whitelist)) {
            foreach ($courses as $title => $lessons) {
                if (!in_array($title, $whitelist, true)) {
                    unset($courses[$title]);
                }
            }
        }

        return $courses;
    }

    /**
     * @return array
     */
    private function fetchCourses(): array
    {
        $this->io->title('Fetching courses...');

        $blueprintFile = __DIR__ . '/../blueprint.json';
        if (file_exists($blueprintFile)) {
            $decodedBlueprint = json_decode(file_get_contents($blueprintFile), true);
            if (count($decodedBlueprint) > 0) {
                return $decodedBlueprint;
            }
        }

        $response = $this->client->get('/courses');

        $courses = [];
        $crawler = new Crawler($response->getBody()->getContents());
        $elements = $crawler->filter('div.w-full.px-4.mb-8 > a');

        $progressBar = $this->io->createProgressBar($elements->count());
        $progressBar->setFormat('<info>[%bar%]</info> %message%');
        $progressBar->start();

        foreach ($elements as $itemElement) {
            $titleElement = new Crawler($itemElement);
            $courseTitle = $titleElement->filter('h3')->text();
            $courseUri = $itemElement->getAttribute('href');

            $progressBar->setMessage($courseTitle);
            $progressBar->advance();

            $chapters = [];
            $response = $this->client->get($courseUri);
            $crawler = new Crawler($response->getBody()->getContents());
            foreach ($crawler->filter('div#chapters div.chapter') as $ignored) {
                foreach ($crawler->filter('a.title') as $a) {
                    if ($a->getAttribute('href') === '#') {
                        continue;
                    }

                    $url = explode('#', $a->getAttribute('href'))[0];
                    $urlParts = explode('/', $url);

                    $chapters[end($urlParts)] = $url;
                }
            }

            $courses[$courseTitle] = $chapters;
        }

        $progressBar->finish();

        if (!file_put_contents($blueprintFile, json_encode($courses, JSON_PRETTY_PRINT))) {
            $this->io->warning('Unable to save course blueprint');
        }

        return $courses;
    }

    /**
     * @return void
     */
    private function login(): void
    {
        $response = $this->client->get('login');

        $csrfToken = '';
        $crawler = new Crawler($response->getBody()->getContents());
        foreach ($crawler->filter('meta') as $input) {
            if ($input->getAttribute('name') === 'csrf-token') {
                $csrfToken = $input->getAttribute('content');
            }
        }

        if (empty($csrfToken)) {
            throw new \RuntimeException('Unable to authenticate');
        }

        $currentUrl = null;
        $this->client->post('login', [
            'headers' => [
                'X-CSRF-TOKEN' => $csrfToken,
            ],
            'form_params' => [
                'email' => $this->configs['EMAIL'],
                'password' => $this->configs['PASSWORD'],
            ],
            'on_stats' => function (TransferStats $stats) use (&$currentUrl) {
                $currentUrl = $stats->getEffectiveUri();
            }
        ]);
        if ((string)$currentUrl !== 'https://vueschool.io') {
            throw new \RuntimeException('Authorization failed.');
        }
    }

    /**
     * @param string $text
     * @param bool $capitalizeFirstCharacter
     *
     * @return mixed|string
     */
    private function dashesToTitle($text, $capitalizeFirstCharacter = true)
    {
        $str = str_replace('-', ' ', ucwords($text, '-'));

        if (!$capitalizeFirstCharacter) {
            $str = lcfirst($str);
        }

        return $str;
    }
}

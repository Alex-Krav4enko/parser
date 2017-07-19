<?php
use Symfony\Component\DomCrawler\Crawler;
require __DIR__ . '/vendor/autoload.php';

$request = 'test';
$pageNumber = 2;

#####################################################

function parallel_map(callable $func, array $items) {
  $childPids = [];
  $result = [];
  foreach ($items as $i => $item) {
    $newPid = pcntl_fork();
    if ($newPid == -1) {
      die('Can\'t fork process');
    } elseif ($newPid) {
      $childPids[] = $newPid;
      if ($i == count($items) - 1) {
        foreach ($childPids as $childPid) {
          pcntl_waitpid($childPid, $status);
          $sharedId = shmop_open($childPid, 'a', 0, 0);
          $shareData = shmop_read($sharedId, 0, shmop_size($sharedId));
          $result[] = unserialize($shareData);
          shmop_delete($sharedId);
          shmop_close($sharedId);
        }
      }
    } else {
      $myPid = getmypid();
      $funcResult = $func($item);
      $shareData = serialize($funcResult);
      $sharedId = shmop_open($myPid, 'c', 0644, strlen($shareData));
      shmop_write($sharedId, $shareData, 0);
      exit(0);
    }
  }
  return $result;
}

function reduce(callable $func, array $array, $initial = null) {
  return array_reduce($array, $func, $initial);
}

#####################################################

function createCrawler(callable $getHtml) {
  return function ($url) use ($getHtml) {
    return new Crawler($getHtml($url));
  };
}

function createGetProxy(array $proxies) {
  return function () use ($proxies) {
    return $proxies ? $proxies[array_rand($proxies, 1)] : [];
  };
}

function newProxy($host, $port, $login, $password) {
  return [
    'host' => $host,
    'port' => $port,
    'login' => $login,
    'password' => $password,
  ];
}

function createGetHtml(callable $getProxy) {
  return function ($url) use ($getProxy) {
    $proxy = $getProxy();
    return file_get_contents($url, false, stream_context_create([
      'http' => array_merge(
        [
          'user_agent' => 'Mozilla/5.0 (X11; Windows x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Safari/537.36',
          'request_fulluri' => true,
        ],
        array_filter([
          'proxy' => $proxy && $proxy['host'] ? 'tcp://' . $proxy['host'] . ':' . ($proxy['port'] ?: 3128) : false,
          'header' => $proxy && $proxy['login'] ? 'Proxy-Authorization: Basic ' . base64_encode($proxy['login'] . ':' . $proxy['password']) : false,
        ])
      )
    ]));
  };
}

function createNormalizeUrl($baseUrl) {
  return function ($request) use ($baseUrl) {
    return $baseUrl . trim($request);
  };
}

function createMassUrlPages(callable $normalizeUrl, $pageNumber) {
  return function ($request) use ($normalizeUrl, $pageNumber) {
    return array_map(function ($number) use ($normalizeUrl, $request, $pageNumber) {
      return $normalizeUrl($request) . '&p=' . $number;
    }, range(0, $pageNumber));
  };
}

function createGetLinks(callable $crawler) {
  return function ($pageUrl) use ($crawler) {
    $crawler($pageUrl)
    ->filter('a.organic__url')
    ->each(function (Crawler $link) {
      return $link->attr('href');
    });
  };
}

#####################################################

$crawler = createCrawler(
  createGetHtml(
    createGetProxy([
      newProxy('127.0.0.1', 3128, null, null),
    ])
  )
);
$normalizeUrl = createNormalizeUrl('https://yandex.ru/search/?text=');
$massUrlPages = createMassUrlPages($normalizeUrl, $pageNumber);
$getLinks = createGetLinks($crawler);

#####################################################

$links =
  reduce('array_merge',
    parallel_map($getLinks,
      $massUrlPages($request)), []);
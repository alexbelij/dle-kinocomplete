<?php

namespace Kinocomplete\FeedLoader;

use GuzzleHttp\Exception\RequestException;
use Webmozart\Assert\Assert;
use Kinocomplete\Feed\Feed;

class PostUpdater
{
  /**
   * FeedLoaderFileParser instance.
   *
   * @var FileParser
   */
  protected $fileParser;

  /**
   * PostProcessor instance.
   *
   * @var PostProcessor
   */
  protected $postProcessor;

  /**
   * FeedProcessor instance.
   *
   * @var FeedProcessor
   */
  protected $feedProcessor;

  /**
   * StatusSender instance.
   *
   * @var StatusSender
   */
  protected $statusSender;

  /**
   * Items loading limit.
   *
   * @var int
   */
  protected $itemsLimit = 0;

  /**
   * Number of parsed items.
   *
   * @var int
   */
  protected $parsedItems = 0;

  /**
   * Bytes loading limit.
   *
   * @var int
   */
  protected $bytesLimit = 0;

  /**
   * Number of loaded bytes.
   *
   * @var int
   */
  protected $loadedBytes = 0;

  /**
   * PostUpdater constructor.
   *
   * @param FileParser $fileParser
   * @param PostProcessor $postProcessor
   * @param FeedProcessor $feedProcessor
   * @param StatusSender $statusSender
   */
  public function __construct(
    FileParser $fileParser,
    PostProcessor $postProcessor,
    FeedProcessor $feedProcessor,
    StatusSender $statusSender
  ) {
    $this->fileParser = $fileParser;
    $this->postProcessor = $postProcessor;
    $this->feedProcessor = $feedProcessor;
    $this->statusSender = $statusSender;
  }

  /**
   * Reset state of instance.
   *
   * @throws \ReflectionException
   */
  protected function resetState()
  {
    $reflection = new \ReflectionClass(self::class);
    $properties = $reflection->getDefaultProperties();

    $this->itemsLimit = $properties['itemsLimit'];
    $this->parsedItems = $properties['parsedItems'];
    $this->bytesLimit = $properties['bytesLimit'];
    $this->loadedBytes = $properties['loadedBytes'];
  }

  /**
   * Download progress handler.
   *
   * @param  $total
   * @param  $loaded
   * @throws \Exception
   */
  public function onDownloadProgress(
    $total,
    $loaded
  ) {
    static $lastLoaded;

    if ($loaded > $lastLoaded)
      $this->loadedBytes += $loaded - $lastLoaded;
    else if ($lastLoaded === 0)
      $this->loadedBytes += $loaded;

    $limit = $this->bytesLimit && $this->bytesLimit < $total
      ? $this->bytesLimit
      : $total;

    $this->statusSender->setStepTasks($limit);
    $this->statusSender->setReadyStepTasks($loaded);

    $lastLoaded = $loaded;

    if (
      $this->bytesLimit &&
      $this->bytesLimit < $this->loadedBytes
    ) throw new \OverflowException();
  }

  /**
   * Parse progress handler.
   *
   * @param  $total
   * @param  $loaded
   * @throws \Exception
   */
  public function onParseProgress(
    $total,
    $loaded
  ) {
    $this->statusSender->setStepTasks($total);
    $this->statusSender->setReadyStepTasks($loaded);
  }

  /**
   * Parse handler.
   *
   * @param array $array
   * @throws \Exception
   */
  public function onParse(
    array $array
  ) {
    if (
      $this->itemsLimit &&
      $this->itemsLimit <= $this->parsedItems
    ) throw new \OverflowException();

    $founded = $this->postProcessor->isVideoArrayInPosts($array);

    if ($founded) {

      $posts = $this->postProcessor->getPostsByVideoArray($array);

      foreach ($posts as $post) {

        $updated = $this->postProcessor->updatePostByVideoArray(
          $post,
          $array
        );

        if ($updated) {

          $this->statusSender->processItem();

        } else {

          $this->statusSender->skipItem();
        }
      }
    }

    ++$this->parsedItems;
  }

  /**
   * Update posts.
   *
   * @param  array $feeds
   * @param  int $itemsLimit
   * @return int
   * @throws \ReflectionException
   */
  public function update(
    array $feeds,
    $itemsLimit = 0
  ) {
    Assert::integer($itemsLimit);

    foreach ($feeds as $feed) {
      Assert::isInstanceOf(
        $feed,
        Feed::class
      );
    }

    if (!$feeds)
      throw new \Exception(
        'Нет фидов для загрузки.'
      );

    $this->itemsLimit = $itemsLimit;
    $this->bytesLimit = $itemsLimit * 10000;

    $this->statusSender->openConnection();
    $this->statusSender->setTotalSteps(count($feeds) *2);

    $limitReached = false;

    foreach ($feeds as $feed) {

      $this->statusSender->nextStep();

      try {

        $this->feedProcessor->downloadFeed(
          $feed,
          [$this, 'onDownloadProgress']
        );

      } catch (\OverflowException $exception) {
      } catch (RequestException $exception) {

        $previous = $exception->getPrevious();

        $limitReached = $previous
          && $previous instanceof \OverflowException;

        if (!$limitReached)
          throw $exception;
      }

      $this->statusSender->nextStep();

      try {

        $this->fileParser->parse(
          $feed,
          [$this, 'onParse'],
          [$this, 'onParseProgress']
        );

      } catch (\OverflowException $e) {

        $limitReached = true;
      }

      $this->feedProcessor->removeFeedFile($feed);

      if ($limitReached)
        break;
    }

    $this->statusSender->closeConnection();

    $parsedItems = $this->parsedItems;

    $this->resetState();

    return $parsedItems;
  }
}
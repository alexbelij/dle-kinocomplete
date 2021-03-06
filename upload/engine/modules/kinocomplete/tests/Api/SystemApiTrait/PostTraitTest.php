<?php

namespace Kinocomplete\Test\Api\SystemApiTrait;

use Kinocomplete\Tests\TestTrait\ContainerTrait;
use Kinocomplete\Api\SystemApiTrait\PostTrait;
use Kinocomplete\Exception\NotFoundException;
use Kinocomplete\ExtraField\ExtraField;
use Kinocomplete\Container\Container;
use Kinocomplete\Post\PostFactory;
use PHPUnit\Framework\TestCase;
use Kinocomplete\Api\SystemApi;
use Kinocomplete\Utils\Utils;
use Webmozart\Assert\Assert;
use Kinocomplete\Post\Post;
use Medoo\Medoo;

class PostTraitTest extends TestCase
{
  use ContainerTrait;

  /**
   * @var SystemApi
   */
  public $instance;

  /**
   * @var Medoo
   */
  public $database;

  /**
   * SystemApiTest constructor.
   *
   * @param null   $name
   * @param array  $data
   * @param string $dataName
   */
  public function __construct($name = null, array $data = [], $dataName = '')
  {
    parent::__construct($name, $data, $dataName);

    $this->database = $this->getContainer()->get('database');

    $this->instance = $this->getMockBuilder(
      PostTrait::class
    )->setMethods([
      'getContainer'
    ])->getMockForTrait();

    $this->instance
      ->method('getContainer')
      ->willReturn($this->getContainer());
  }

  /**
   * Testing `addPost` method.
   *
   * @return Post
   * @throws \Exception
   */
  public function testCanAddPost()
  {
    $title = 'Post';

    $post = new Post();
    $post->title = $title;

    $addedPost = $this->instance->addPost($post);

    Assert::isInstanceOf(
      $addedPost,
      Post::class
    );

    Assert::stringNotEmpty(
      $addedPost->id
    );

    Assert::same(
      $addedPost->title,
      $title
    );

    // Checking related post extra.
    $postExtras = $this->database->get(
      'post_extras',
      'news_id',
      ['news_id' => $addedPost->id]
    );

    Assert::count($postExtras, 1);

    return $addedPost;
  }

  /**
   * Testing `getPost` method.
   *
   * @param   Post $post
   * @return  string
   * @throws  NotFoundException
   * @depends testCanAddPost
   */
  public function testCanGetPost(
    Post $post
  ) {
    $fetchedPost = $this->instance->getPost(
      $post->id
    );

    Assert::isInstanceOf(
      $fetchedPost,
      Post::class
    );

    Assert::same(
      $post->id,
      $fetchedPost->id
    );

    Assert::same(
      $post->title,
      $fetchedPost->title
    );

    return $fetchedPost;
  }

  /**
   * Testing `updatePost` method.
   *
   * @param   Post $post
   * @return  Post
   * @throws  NotFoundException
   * @depends testCanAddPost
   */
  public function testCanUpdatePost(
    Post $post
  ) {
    $post->title = Utils::randomString();

    $this->instance->updatePost(
      $post
    );

    $updatedPost = $this->instance->getPost(
      $post->id
    );

    Assert::same(
      $updatedPost->title,
      $post->title
    );

    return $updatedPost;
  }

  /**
   * Testing `getPosts` method.
   *
   * @param   Post $post
   * @return  mixed
   * @throws  \Exception
   * @depends testCanUpdatePost
   */
  public function testCanGetPosts(
    Post $post
  ) {
    $fetchedPosts = $this->instance->getPosts([
      'id' => $post->id
    ]);

    Assert::notEmpty($fetchedPosts);

    $fetchedPost = $fetchedPosts[0];

    Assert::isInstanceOf(
      $fetchedPost,
      Post::class
    );

    Assert::same(
      $post->id,
      $fetchedPost->id
    );

    Assert::same(
      $post->title,
      $fetchedPost->title
    );

    return $fetchedPost->id;
  }

  /**
   * Testing `getPosts` method with "join".
   *
   * @throws \Exception
   */
  public function testCanGetPostsWithJoin()
  {
    $table   = 'post';
    $where   = ['1'];
    $join    = ['2'];
    $columns = ['3'];

    $database = $this->getMockBuilder(Medoo::class)
      ->disableOriginalConstructor()
      ->getMock();

    /** @var PostFactory $factory */
    $factory = $this->getContainer()->get('post_factory');

    $expectedInstance = new Post();
    $expectedArray = $factory->toDatabaseArray($expectedInstance);

    $database
      ->expects($this->exactly(2))
      ->method('select')
      ->with(
        $table,
        $join,
        $columns,
        $where
      )->willReturn([$expectedArray]);

    $trait = $this->getMockBuilder(
      PostTrait::class
    )->setMethods([
      'getContainer'
    ])->getMockForTrait();

    $trait
      ->method('getContainer')
      ->willReturn(new Container([
        'database' => $database,
        'post_factory' => $factory
      ]));

    /** @var PostTrait $trait */
    $instances = $trait->getPosts(
      $where,
      $join,
      $columns
    );

    Assert::count($instances, 1);

    Assert::eq(
      $instances[0],
      $expectedInstance
    );

    $arrays = $trait->getPosts(
      $where,
      $join,
      $columns,
      true
    );

    Assert::count($arrays, 1);

    Assert::same(
      $arrays[0],
      $expectedArray
    );
  }

  /**
   * Testing `hasPosts` method.
   *
   * @param   string $id
   * @return  mixed
   * @throws  \Exception
   * @depends testCanGetPosts
   */
  public function testCanHasPosts($id)
  {
    Assert::true(
      $this->instance->hasPosts([
        'id[~]' => $id
      ])
    );

    Assert::false(
      $this->instance->hasPosts([
        'id[~]' => '-20'
      ])
    );

    return $id;
  }

  /**
   * Testing `countPosts` method.
   *
   * @param   string $id
   * @return  mixed
   * @throws  \Exception
   * @depends testCanHasPosts
   */
  public function testCanCountPosts($id)
  {
    $count = $this->instance->countPosts([
      'id' => $id
    ]);

    Assert::same($count, 1);

    return $id;
  }

  /**
   * Testing `countPosts` method with join.
   *
   * @throws \Exception
   */
  public function testCanCountPostsWithJoin()
  {
    $where   = ['1'];
    $join    = ['2'];
    $columns = ['3'];

    $expected = 5;

    $database = $this->getMockBuilder(Medoo::class)
      ->disableOriginalConstructor()
      ->getMock();

    $database
      ->expects($this->once())
      ->method('count')
      ->with(
        'post',
        $join,
        $columns,
        $where
      )->willReturn($expected);

    $trait = $this->getMockBuilder(
      PostTrait::class
    )->setMethods([
      'getContainer'
    ])->getMockForTrait();

    $trait
      ->method('getContainer')
      ->willReturn(new Container([
        'database' => $database,
      ]));

    /** @var PostTrait $trait */
    $result = $trait->countPosts(
      $where,
      $join,
      $columns
    );

    Assert::same(
      $result,
      $expected
    );
  }

  /**
   * Testing `removePost` method.
   *
   * @param   string $id
   * @throws  NotFoundException
   * @depends testCanCountPosts
   */
  public function testCanRemovePost($id)
  {
    $isRemoved = $this->instance->removePost($id);

    Assert::true($isRemoved);

    // Checking related post extra.
    $postExtras = $this->database->get(
      'post_extras',
      'news_id',
      ['news_id' => $id]
    );

    Assert::isEmpty($postExtras);
  }

  /**
   * Testing `removePosts` method.
   *
   * @throws \Exception
   */
  public function testCanRemovePosts()
  {
    $addedPost = $this->instance->addPost(
      new Post()
    );

    $count = $this->instance->removePosts([
      'id' => $addedPost->id
    ]);

    Assert::same($count, 1);

    // Checking related post extra.
    $postExtras = $this->database->get(
      'post_extras',
      'news_id',
      ['news_id' => $addedPost->id]
    );

    Assert::isEmpty($postExtras);
  }

  /**
   * Testing `getPost` method exceptions.
   *
   * @throws NotFoundException
   */
  public function testCannotGetPost()
  {
    $this->expectException(NotFoundException::class);

    $this->instance->getPost('-20');
  }

  /**
   * Testing `updatePost` method exceptions.
   *
   * @throws NotFoundException
   */
  public function testCannotUpdatePost()
  {
    $this->expectException(\InvalidArgumentException::class);

    $this->instance->updatePost(new Post());
  }

  /**
   * Testing `removePost` method exceptions.
   *
   * @throws NotFoundException
   */
  public function testCannotRemovePost()
  {
    $this->expectException(NotFoundException::class);

    $this->instance->removePost('-20');
  }

  /**
   * Testing `addExtraFieldsToSearch` method.
   *
   * @return array
   * @throws \Exception
   */
  public function testCanAddExtraFieldsToSearch()
  {
    $postId = (string) rand(1000, 9999);

    $firstValues = [
      'first',
      'second',
      'third',
    ];

    $firstField = new ExtraField();
    $firstField->name = Utils::randomString();
    $firstField->label = Utils::randomString();
    $firstField->type = ExtraField::TEXT_TYPE;
    $firstField->value = join(', ', $firstValues);
    $firstField->linked = true;

    $secondValues = [
      'third',
      'fourth',
      'fifth',
    ];

    $secondField = new ExtraField();
    $secondField->name = Utils::randomString();
    $secondField->label = Utils::randomString();
    $secondField->type = ExtraField::TEXT_TYPE;
    $secondField->value = join(', ', $secondValues);
    $secondField->linked = true;

    $count = $this->instance->addExtraFieldsToSearch(
      $postId,
      [$firstField, $secondField]
    );

    $expectedCount = count(
      $firstValues
    ) + count(
      $secondValues
    );

    Assert::same(
      $count,
      $expectedCount
    );

    $count = $this->database->count(
      'xfsearch',
      ['news_id' => $postId]
    );

    Assert::same(
      $count,
      $expectedCount
    );

    return [
      'post_id' => $postId,
      'count' => $expectedCount,
    ];
  }

  /**
   * Testing `removeExtraFieldsFromSearch` method.
   *
   * @param   array $options
   * @throws  \Exception
   * @depends testCanAddExtraFieldsToSearch
   */
  public function testCanRemoveExtraFieldsFromSearch($options)
  {
    $count = $this->instance->removeExtraFieldsFromSearch(
      $options['post_id']
    );

    Assert::same(
      $count,
      $options['count']
    );

    $count = $this->database->count(
      'xfsearch',
      ['news_id' => $options['post_id']]
    );

    Assert::same($count, 0);
  }

  /**
   * Testing `addPost` method with linked field.
   *
   * @return Post
   * @throws \Exception
   */
  public function testCanAddPostWithLinkedField()
  {
    $values = [
      'first',
      'second',
      'third',
    ];

    $expectedCount = count($values);

    $extraField = new ExtraField();
    $extraField->name = Utils::randomString();
    $extraField->label = Utils::randomString();
    $extraField->type = ExtraField::TEXT_TYPE;
    $extraField->value = join(', ', $values);
    $extraField->linked = true;

    $post = new Post();
    $post->title = Utils::randomString();
    $post->extraFields = [$extraField];

    $post = $this->instance->addPost($post);

    $count = $this->database->count(
      'xfsearch',
      ['news_id' => $post->id]
    );

    Assert::same(
      $count,
      $expectedCount
    );

    return $post;
  }

  /**
   * Testing `updatePost` with linked field.
   *
   * @param   Post $post
   * @return  Post
   * @throws  NotFoundException
   * @depends testCanAddPostWithLinkedField
   */
  public function testCanUpdatePostWithLinkedField(Post $post)
  {
    $values = [
      'third',
      'fourth',
    ];

    $expectedCount = count($values);

    /** @var ExtraField $extraField */
    $extraField = current($post->extraFields);

    $extraField->value = join(', ', $values);

    $this->instance->updatePost($post);

    $count = $this->database->count(
      'xfsearch',
      ['news_id' => $post->id]
    );

    Assert::same(
      $count,
      $expectedCount
    );

    return $post;
  }

  /**
   * Testing `removePost` method with linked field.
   *
   * @param   Post $post
   * @return  Post
   * @throws  NotFoundException
   * @depends testCanUpdatePostWithLinkedField
   */
  public function testCanRemovePostWithLinkedField(Post $post)
  {
    $this->instance->removePost($post->id);

    $count = $this->database->count(
      'xfsearch',
      ['news_id' => $post->id]
    );

    Assert::same($count, 0);

    return $post;
  }

  /**
   * Testing `removePosts` method with linked field.
   *
   * @param Post $post
   * @throws \Exception
   * @depends testCanRemovePostWithLinkedField
   */
  public function testCanRemovePostsWithLinkedField(Post $post)
  {
    $extraField = current($post->extraFields);

    $expectedCount = count(
      explode(',', $extraField->value)
    );

    Assert::same($expectedCount, 2);

    $this->instance->addPost($post);

    $count = $this->database->count(
      'xfsearch',
      ['news_id' => $post->id]
    );

    Assert::same(
      $count,
      $expectedCount
    );

    $this->instance->removePosts([
      'id' => $post->id
    ]);

    $count = $this->database->count(
      'xfsearch',
      ['news_id' => $post->id]
    );

    Assert::same($count, 0);
  }
}

<?php

namespace Drupal\Tests\subrequests\Unit\Blueprint;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\subrequests\Blueprint\BlueprintManager;
use Drupal\subrequests\Normalizer\JsonBlueprintDenormalizer;
use Drupal\subrequests\Normalizer\MultiresponseNormalizer;
use Drupal\subrequests\SubrequestsTree;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Serializer;

/**
 * @coversDefaultClass \Drupal\subrequests\Blueprint\BlueprintManager
 * @group subrequests
 */
class BlueprintManagerTest extends UnitTestCase {

  /**
   * @var \Drupal\subrequests\Blueprint\BlueprintManager
   */
  protected $sut;

  protected function setUp() {
    parent::setUp();
    $denormalizer = $this->prophesize(JsonBlueprintDenormalizer::class);
    $denormalizer->denormalize(Argument::type('array'), SubrequestsTree::class, 'json', [])
      ->willReturn(new SubrequestsTree());
    $denormalizer->supportsDenormalization(Argument::type('array'), SubrequestsTree::class, 'json')
      ->willReturn([])->willReturn(TRUE);
    $denormalizer->setSerializer(Argument::any())->willReturn(NULL);
    $normalizer = $this->prophesize(MultiresponseNormalizer::class);
    $normalizer->normalize(Argument::type('array'), 'multipart-related', Argument::type('array'))
      ->willReturn('Booh!');
    $normalizer->supportsNormalization(Argument::type('array'), 'multipart-related')
      ->willReturn([])->willReturn(TRUE);
    $serializer = new Serializer(
      [$denormalizer->reveal(), $normalizer->reveal()],
      [new JsonDecode()]
    );
    $this->sut = new BlueprintManager($serializer);
  }

  /**
   * @covers ::parse
   */
  public function testParse() {
    $parsed = $this->sut->parse('[]', Request::create('foo'));
    $this->assertInstanceOf(SubrequestsTree::class, $parsed);
    $this->assertSame('/foo', $parsed->getMasterRequest()->getPathInfo());
  }

  /**
   * @covers ::combineResponses
   */
  public function testCombineResponses() {
    $responses = [
      Response::create('foo', 200, ['lorem' => 'ipsum', 'Content-Type' => 'sparrow']),
      Response::create('bar', 201, ['dolor' => 'sid', 'Content-Type' => 'sparrow']),
    ];
    $combined = $this->sut->combineResponses($responses);
    $this->assertInstanceOf(CacheableResponse::class, $combined);
    $content_type = $combined->headers->get('Content-Type');
    $this->assertStringStartsWith('multipart/related; boundary="', $content_type);
    $this->assertStringEndsWith('", type=sparrow', $content_type);
    $this->assertSame('Booh!', $combined->getContent());
  }

}
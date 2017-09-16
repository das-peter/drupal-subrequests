<?php

namespace Drupal\Tests\subrequests\Unit;

use Drupal\subrequests\JsonPathReplacer;
use Drupal\subrequests\Subrequest;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @coversDefaultClass \Drupal\subrequests\JsonPathReplacer
 * @group subrequests
 */
class JsonPathReplacerTest extends UnitTestCase {

  /**
   * @var \Drupal\subrequests\JsonPathReplacer
   */
  protected $sut;

  protected function setUp() {
    parent::setUp();
    $this->sut = new JsonPathReplacer();
  }

  /**
   * @covers ::replaceBatch
   */
  public function testReplaceBatch() {
    $batch = $responses = [];
    $batch[] = new Subrequest([
      'uri' => '/ipsum/{{foo.body@$.things[*]}}/{{bar.body@$.things[*]}}',
      'action' => 'sing',
      'requestId' => 'oop',
      'headers' => [],
      '_resolved' => FALSE,
      'body' => ['answer' => '{{foo.body@$.stuff}}'],
      'waitFor' => ['foo'],
    ]);
    $batch[] = new Subrequest([
      'uri' => '/dolor/{{foo.body@$.stuff}}',
      'action' => 'create',
      'requestId' => 'oof',
      'headers' => [],
      '_resolved' => FALSE,
      'body' => 'bar',
      'waitFor' => ['foo'],
    ]);
    $response = Response::create('{"things":["what","keep","talking"],"stuff":42}');
    $response->headers->set('Content-ID', '<foo>');
    $responses[] = $response;
    $response = Response::create('{"things":["the","plane","is"],"stuff":"delayed"}');
    $response->headers->set('Content-ID', '<bar>');
    $responses[] = $response;
    $actual = $this->sut->replaceBatch($batch, $responses);
    $this->assertCount(10, $actual);
    $paths = array_map(function (Subrequest $subrequest) {
      return $subrequest->uri;
    }, $actual);
    $expected_paths = [
      '/ipsum/what/the',
      '/ipsum/keep/the',
      '/ipsum/talking/the',
      '/ipsum/what/plane',
      '/ipsum/keep/plane',
      '/ipsum/talking/plane',
      '/ipsum/what/is',
      '/ipsum/keep/is',
      '/ipsum/talking/is',
      '/dolor/42',
    ];
    $this->assertEquals($expected_paths, $paths);
    $this->assertEquals(['answer' => 42], $actual[0]->body);
  }

}

<?php

namespace Drupal\Tests\subrequests\Normalizer;

use Drupal\subrequests\Normalizer\MultiresponseNormalizer;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @coversDefaultClass \Drupal\subrequests\Normalizer\MultiresponseNormalizer
 * @group subrequests
 */
class MultiresponseNormalizerTest extends UnitTestCase {

  /**
   * @var \Drupal\subrequests\Normalizer\MultiresponseNormalizer
   */
  protected $sut;

  protected function setUp() {
    parent::setUp();
    $this->sut = new MultiresponseNormalizer();
  }

  /**
   * @dataProvider dataProviderSupportsNormalization
   * @covers ::supportsNormalization
   */
  public function testSupportsNormalization($data, $format, $is_supported) {
    $actual = $this->sut->supportsNormalization($data, $format);
    $this->assertSame($is_supported, $actual);
  }

  public function dataProviderSupportsNormalization() {
    return [
      [[Response::create('')], 'multipart-related', TRUE],
      [[], 'multipart-related', TRUE],
      [[Response::create('')], 'fail', FALSE],
      [NULL, 'multipart-related', FALSE],
      [[Response::create(''), NULL], 'multipart-related', FALSE],
    ];
  }

  /**
   * @covers ::normalize
   */
  public function testNormalize() {
    $delimiter = $this->getRandomGenerator()->string();
    $data = [Response::create('Foo!'), Response::create('Bar')];
    $actual = $this->sut->normalize($data, NULL, ['delimiter' => $delimiter]);
    $this->assertStringStartsWith('--' . $delimiter, $actual);
    $this->assertStringEndsWith('--' . $delimiter . '--', $actual);
    $this->assertRegExp("/\r\nFoo!\r\n/", $actual);
    $this->assertRegExp("/\r\nBar\r\n/", $actual);
  }

}

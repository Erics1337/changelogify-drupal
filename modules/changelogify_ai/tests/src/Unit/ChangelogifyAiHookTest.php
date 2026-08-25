<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the procedural integration hooks exposed by Changelogify AI.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class ChangelogifyAiHookTest extends TestCase {

  /**
   * The humanize action alters the release entity base form.
   */
  public function testReleaseFormAlterUsesActualBaseFormId(): void {
    require_once dirname(__DIR__, 3) . '/changelogify_ai.module';

    self::assertTrue(function_exists('changelogify_ai_form_changelogify_release_form_alter'));
  }

}

<?php
declare(strict_types=1);

namespace DAoCCMS\Setup;

/**
 * Renders the wizard's guide character. Callers pass trusted, hand-written
 * copy (may contain inline tags like <code> or <b>), the same trust model
 * the rest of the wizard uses for its lead-text — never raw user input.
 */
class Britty
{
    /** @param string|string[] $lines One or more paragraphs. */
    public static function say($lines): void
    {
        $lines = is_array($lines) ? $lines : [$lines];
        ?>
        <div class="britty">
            <div class="britty-portrait-wrap">
                <?php self::portrait(); ?>
            </div>
            <div class="britty-bubble">
                <span class="britty-name">Britty</span>
                <?php foreach ($lines as $line): ?>
                    <p><?= $line ?></p>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private static function portrait(): void
    {
        $installer = true; // satisfies britty-portrait.svg.php's include guard
        include __DIR__ . '/britty-portrait.svg.php';
    }
}

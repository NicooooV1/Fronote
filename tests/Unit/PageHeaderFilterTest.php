<?php
declare(strict_types=1);

namespace Tests\Unit;

use API\UI\PageHeaderFilter as F;
use PHPUnit\Framework\TestCase;

final class PageHeaderFilterTest extends TestCase
{
    private function page(string $content, string $title = 'Clubs'): string
    {
        return '<div class="top-header mh"><div class="page-title"><h1>' . $title . '<button>★</button></h1>' . F::SUBTITLE_MARK
            . '</div><div class="header-actions">' . F::ACTIONS_MARK . '</div></div>' . F::CONTENT_MARK . '<div class="content-container">' . $content . '</div>';
    }

    public function testContentHeaderIsRemovedAndActionsMoved(): void
    {
        $out = F::apply($this->page('<div class="content-wrapper"><div class="content-header"><h1><i class="fas fa-x"></i> Clubs</h1><a href="creer.php" class="btn">Créer</a></div><div class="card">x</div></div>'));
        $this->assertSame(1, substr_count($out, '<h1'));
        $this->assertStringContainsString('<a href="creer.php" class="btn">Créer</a>' . F::ACTIONS_MARK, $out);
        $this->assertStringNotContainsString('content-header', $out);
        $this->assertStringContainsString('<div class="card">x</div>', $out);
    }

    public function testDescriptionBecomesSubtitle(): void
    {
        $out = F::apply($this->page('<div class="page-header"><h1>Clubs</h1><p>Les activités du midi.</p></div>'));
        $this->assertStringContainsString('<p class="subtitle mh-sub">Les activités du midi.</p>', $out);
    }

    public function testRepeatedH2IsRemovedButOtherH2Kept(): void
    {
        $this->assertStringNotContainsString('<h2>', F::apply($this->page('<h2>Clubs</h2><div class="card">a</div>')));
        $this->assertStringContainsString('<h2>Inscriptions</h2>', F::apply($this->page('<h2>Inscriptions</h2><div class="card">a</div>')));
    }

    public function testNothingTouchedAfterRealContent(): void
    {
        $html = $this->page('<table><tr><td>1</td></tr></table><div class="content-header"><h1>Autre</h1></div>');
        $this->assertSame($html, F::apply($html));
    }

    public function testNoMarkerNoChange(): void
    {
        $this->assertSame('{"ok":true}', F::apply('{"ok":true}'));
    }
}

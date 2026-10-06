<?php

namespace Tests\Unit;

use App\Services\AdhkarPdfService;
use ReflectionMethod;
use Tests\TestCase;

class AdhkarPdfPaginationTest extends TestCase
{
    /**
     * @param  list<float>  $heights
     * @return list<list<int>>
     */
    private function pages(array $heights, float $cover = 40.0, float $closing = 30.0): array
    {
        $paginate = new ReflectionMethod(AdhkarPdfService::class, 'paginate');

        return array_column($paginate->invoke(app(AdhkarPdfService::class), $heights, $cover, $closing), 'cards');
    }

    public function test_a_card_that_does_not_fit_starts_the_next_page_whole(): void
    {
        // 40 cover + 60 + 60 = 160 of 170; the third card (60) would cross the page end.
        $this->assertSame([[0, 1], [2]], $this->pages([60, 60, 60]));
    }

    public function test_the_closing_moves_with_the_last_card_instead_of_sitting_alone(): void
    {
        // Page two holds both cards (150 of 170) but not the 30 mm closing, so the last card goes with it.
        $this->assertSame([[0], [1], [2]], $this->pages([120, 80, 70]));
    }

    public function test_a_card_alone_on_the_cover_page_is_never_moved_to_leave_the_cover_alone(): void
    {
        $this->assertSame([[0], [1]], $this->pages([200, 50]));
    }
}

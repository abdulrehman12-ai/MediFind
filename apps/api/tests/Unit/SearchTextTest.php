<?php

namespace Tests\Unit;

use App\Discovery\SearchText;
use PHPUnit\Framework\TestCase;

class SearchTextTest extends TestCase
{
    public function test_like_patterns_escape_wildcard_characters(): void
    {
        $this->assertSame('a\\%b\\_c\\\\', SearchText::escapeLike('a%b_c\\'));
        $this->assertSame('a\\%%', SearchText::prefix('a%'));
        $this->assertSame('%a\\%%', SearchText::contains('a%'));
    }
}

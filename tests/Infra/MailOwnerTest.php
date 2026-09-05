<?php

namespace Talivio\Sdk\Tests\Infra;

use PHPUnit\Framework\TestCase;
use Talivio\Sdk\Infra\Support\MailOwner;

class MailOwnerTest extends TestCase
{
    public function test_a_tag_round_trips_through_the_description_field(): void
    {
        $owner = new MailOwner('mailio', 'company-7', 'Acme Ltd');

        $this->assertSame('Acme Ltd [mailio:company-7]', $owner->toDescription());

        $parsed = MailOwner::fromDescription($owner->toDescription());

        $this->assertSame('mailio', $parsed->product);
        $this->assertSame('company-7', $parsed->ref);
        $this->assertSame('Acme Ltd', $parsed->label);
        $this->assertTrue($parsed->is($owner));
    }

    public function test_a_label_is_optional(): void
    {
        $owner = new MailOwner('contentio', 'site-42');

        $this->assertSame('[contentio:site-42]', $owner->toDescription());
        $this->assertSame('', MailOwner::fromDescription('[contentio:site-42]')->label);
    }

    /**
     * Brackets and colons delimit the tag, so a label carrying them would
     * make it unparseable — they're replaced, not passed through.
     */
    public function test_delimiters_in_the_product_or_ref_are_neutralised(): void
    {
        $owner = new MailOwner('Mail:io', 'company [7]', 'Acme');

        $this->assertSame('Acme [mail-io:company-7-]', $owner->toDescription());
        $this->assertNotNull(MailOwner::fromDescription($owner->toDescription()));
    }

    /**
     * Every domain created before this convention, and anything a human
     * typed into mailcow by hand. Null means "unknown", NOT "unowned".
     */
    public function test_a_description_without_a_tag_is_unknown_not_unowned(): void
    {
        $this->assertNull(MailOwner::fromDescription('Contentio'));
        $this->assertNull(MailOwner::fromDescription(''));
        $this->assertNull(MailOwner::fromDescription(null));
        $this->assertNull(MailOwner::fromDescription('Acme [not a tag]'));
    }

    public function test_two_tags_naming_the_same_owner_match_regardless_of_label(): void
    {
        $a = new MailOwner('mailio', 'company-7', 'Acme Ltd');
        $b = new MailOwner('mailio', 'company-7', 'Acme Limited');
        $c = new MailOwner('mailio', 'company-8', 'Acme Ltd');

        $this->assertTrue($a->is($b));
        $this->assertFalse($a->is($c));
    }
}

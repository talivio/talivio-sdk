<?php

namespace Talivio\Sdk\Infra\Support;

use Stringable;

/**
 * Who a mail domain belongs to, written into the mail host's own
 * `description` field so the answer survives outside our databases.
 *
 * ⚠️ WHY THIS EXISTS: one mailcow instance serves every Talivio product.
 * Before this, each product stamped the domain with its own label
 * ("Contentio", "Talivio", the customer's company name) and kept ownership
 * only in its own tables. So "who owns mail.example.com?" could only be
 * answered by querying every product's database in turn, and a domain
 * created by a product that was later retired became unattributable — the
 * mail server itself knew nothing. Encoding the owner in the description
 * makes the mail host the source of truth it already physically is.
 *
 * Format: `Human label [product:ref]`. The human half is what shows up in
 * mailcow's own admin UI, so it stays readable; the bracketed half is what
 * code parses. A description with no bracket parses to null, which is
 * exactly what every pre-existing domain returns — treat null as
 * "unknown/legacy", never as "unowned, safe to delete".
 */
final class MailOwner implements Stringable
{
    /**
     * @param  string  $product  the product that provisioned the domain, e.g. "mailio", "contentio"
     * @param  string  $ref  that product's own id for the owner, e.g. "company-7", "site-42"
     * @param  string  $label  human-readable name shown in the mail host's UI
     */
    public function __construct(
        public readonly string $product,
        public readonly string $ref,
        public readonly string $label = '',
    ) {}

    public function toDescription(): string
    {
        $tag = '['.$this->slug($this->product).':'.$this->slug($this->ref).']';
        $label = trim($this->label);

        return $label === '' ? $tag : $label.' '.$tag;
    }

    /**
     * Parses a description written by toDescription(). Null when the text
     * carries no owner tag — every domain created before this convention,
     * and anything a human typed by hand in mailcow.
     */
    public static function fromDescription(?string $description): ?self
    {
        if ($description === null || ! preg_match('/^(.*?)\s*\[([a-z0-9._-]+):([a-z0-9._-]+)\]\s*$/i', $description, $m)) {
            return null;
        }

        return new self(strtolower($m[2]), strtolower($m[3]), trim($m[1]));
    }

    /** Whether this tag and another name the same owner (label ignored). */
    public function is(self $other): bool
    {
        return $this->product === $other->product && $this->ref === $other->ref;
    }

    public function __toString(): string
    {
        return $this->toDescription();
    }

    /**
     * Brackets and colons are the delimiters, so they can never appear in
     * either half — a label containing them would make the tag unparseable.
     */
    private function slug(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: 'unknown';
    }
}

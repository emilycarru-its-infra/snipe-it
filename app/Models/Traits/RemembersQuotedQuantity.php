<?php

namespace App\Models\Traits;

/**
 * A procurement line that remembers what the vendor quoted for it.
 *
 * Once a quote is on file, the first change to a line's quantity keeps the
 * quoted figure in `quoted_quantity`, so a request quoted at three and cut to
 * two still knows it was three. The line carries that figure forward as it
 * moves from store request to requisition to vendor order, and the order mail
 * puts every difference in front of the vendor before they ship the quote.
 */
trait RemembersQuotedQuantity
{
    protected static function bootRemembersQuotedQuantity(): void
    {
        static::updating(function (self $line) {
            if ($line->isDirty('quantity') && $line->quoted_quantity === null && $line->quoteOnFile()) {
                $line->quoted_quantity = (int) $line->getOriginal('quantity');
            }
        });
    }

    /** Whether the document this line belongs to has a vendor quote recorded. */
    abstract public function quoteOnFile(): bool;

    /** True when we are ordering a different quantity from the one quoted. */
    public function differsFromQuote(): bool
    {
        return $this->quoted_quantity !== null && (int) $this->quoted_quantity !== (int) $this->quantity;
    }

    /**
     * The quoted quantity to hand the next stage: the remembered figure, or the
     * current quantity when a quote is on file and nothing has changed yet.
     */
    public function quotedQuantityForward(): ?int
    {
        if ($this->quoted_quantity !== null) {
            return (int) $this->quoted_quantity;
        }

        return $this->quoteOnFile() ? (int) $this->quantity : null;
    }
}

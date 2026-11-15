<?php

namespace App\Contracts;

/**
 * A model that can describe itself to a human.
 *
 * One declaration, several consumers: the audit trail freezes changes and snapshots
 * through it today, and an approval engine can present a before/after diff from the
 * same schema tomorrow without the model being described twice.
 *
 * See docs/snapshot-schema.md for the entry format.
 */
interface HasSnapshotSchema
{
    /**
     * How each field of this record is labelled, typed and resolved.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function snapshotSchema(): array;
}

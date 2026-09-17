<?php

namespace App\Sync;

/**
 * How a synchronization handler resolves a stale-version conflict.
 *
 * Policies are declared per resource because the correct strategy is domain
 * specific: a student record may be worth merging, while an attendance record
 * usually has a single authoritative writer.
 */
enum ConflictPolicy: string
{
    /** The server record is authoritative; the client write is discarded. */
    case ServerWins = 'server_wins';

    /** The client write is reapplied on top of the current server version. */
    case ClientWins = 'client_wins';

    /** Client fields are merged onto the current server record, then saved. */
    case FieldLevelMerge = 'field_level_merge';

    /** Report the conflict and let the client decide. Never overwrites. */
    case ManualResolution = 'manual_resolution';
}

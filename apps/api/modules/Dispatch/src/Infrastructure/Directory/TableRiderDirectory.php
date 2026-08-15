<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Directory;

use EruoFood\Dispatch\Application\Port\RiderDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Reads the rider record straight from Marketplace's table.
 *
 * A soft reference — a table name and column names, no class import, no foreign
 * key — which is the boundary convention the rest of the codebase already uses
 * for cross-context reads (see `RiderLocationService::assertRiderBelongsToUser`).
 * Marketplace can restructure its aggregate freely; only this one file has to
 * follow.
 *
 * Read-only, and only the columns Dispatch has a reason for.
 */
final class TableRiderDirectory implements RiderDirectory
{
    private const COLUMNS = ['id', 'user_id', 'name', 'phone', 'status', 'vehicle_type'];

    public function ownerOf(string $riderId): ?string
    {
        $value = DB::table('marketplace_riders')->where('id', $riderId)->value('user_id');

        return $value === null ? null : (string) $value;
    }

    public function riderIdFor(string $userId): ?string
    {
        $value = DB::table('marketplace_riders')->where('user_id', $userId)->value('id');

        return $value === null ? null : (string) $value;
    }

    public function summary(string $riderId): ?array
    {
        $row = DB::table('marketplace_riders')->select(self::COLUMNS)->where('id', $riderId)->first();

        return $row === null ? null : $this->shape((array) $row);
    }

    public function summaries(array $riderIds): array
    {
        if ($riderIds === []) {
            return [];
        }

        $rows = DB::table('marketplace_riders')
            ->select(self::COLUMNS)
            ->whereIn('id', array_values(array_unique($riderIds)))
            ->get();

        $byId = [];

        foreach ($rows as $row) {
            $shaped = $this->shape((array) $row);
            $byId[$shaped['id']] = $shaped;
        }

        return $byId;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, user_id: string, name: string, phone: string, status: string, vehicle_type: string|null}
     */
    private function shape(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'user_id' => (string) $row['user_id'],
            'name' => (string) $row['name'],
            'phone' => (string) $row['phone'],
            'status' => (string) $row['status'],
            // Deprecated, kept readable through the M26 transition: the
            // backfill derives vehicles from it and the report reconciles
            // against it. Nothing in dispatch decides eligibility from it.
            'vehicle_type' => $row['vehicle_type'] === null ? null : (string) $row['vehicle_type'],
        ];
    }
}

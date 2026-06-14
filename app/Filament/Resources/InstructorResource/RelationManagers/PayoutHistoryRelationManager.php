<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Enums\LedgerEntryType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Per-instructor payout history. Read-only. The id is shown for
// cross-referencing with the mock provider's operations table when
// chasing a `reconciling` row.
class PayoutHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Payout history';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query
                ->where('type', LedgerEntryType::InstructorPayout->value)
                ->orderByDesc('id'))
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Row id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('idempotency_key')
                    ->label('Idempotency key')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->money('USD', divideBy: 100)
                    ->sortable(),

                Tables\Columns\TextColumn::make('meta.status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'reconciling' => 'warning',
                        'pending' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('pending'),

                Tables\Columns\TextColumn::make('meta.sent_at')
                    ->label('Sent at')
                    ->placeholder('—')
                    ->since(),

                Tables\Columns\TextColumn::make('meta.failed_at')
                    ->label('Failed at')
                    ->placeholder('—')
                    ->since(),

                Tables\Columns\TextColumn::make('meta.provider_reference')
                    ->label('Provider reference')
                    ->placeholder('—')
                    ->searchable()
                    ->copyable(),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}

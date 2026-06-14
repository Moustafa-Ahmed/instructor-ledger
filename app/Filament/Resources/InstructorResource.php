<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\InstructorResource\Pages;
use App\Filament\Resources\InstructorResource\RelationManagers;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

// Read-only ops view. Eager-loads `payoutLedgerEntries` so the table renders
// with one SQL query for the page's payout rows, no per-row N+1. The math
// lives on `User::payoutBalance()`; this class is pure presentation.
class InstructorResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Payouts';

    protected static ?string $modelLabel = 'Instructor';

    protected static ?string $pluralModelLabel = 'Instructors';

    public static function form(Form $form): Form
    {
        return $form;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => self::scopeToInstructors($query)
                ->with(['payoutLedgerEntries' => fn(Relation $q) => $q->select(['id', 'user_id', 'amount_cents', 'meta'])]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('payout_destination')
                    ->label('Payout destination')
                    ->placeholder('—')
                    ->searchable(),

                // Earned and Paid are plain TextColumns (no badge) so
                // the Outstanding badge stands out as the action-target column.
                Tables\Columns\TextColumn::make('earned_cents')
                    ->label('Earned')
                    ->money('USD', divideBy: 100)
                    ->sortable()
                    ->getStateUsing(fn(User $record): int => $record->payoutBalance()['earned_cents']),

                Tables\Columns\TextColumn::make('paid_cents')
                    ->label('Paid')
                    ->money('USD', divideBy: 100)
                    ->sortable()
                    ->getStateUsing(fn(User $record): int => $record->payoutBalance()['paid_cents']),

                Tables\Columns\TextColumn::make('outstanding_cents')
                    ->label('Outstanding')
                    ->money('USD', divideBy: 100)
                    ->sortable()
                    ->badge()
                    // Positive (we owe) is informational; negative (failed
                    // attempt, money owed back) is a recovery situation;
                    // zero means settled.
                    ->color(fn(int $state): string => match (true) {
                        $state < 0 => 'warning',
                        $state > 0 => 'info',
                        default => 'gray',
                    })
                    ->getStateUsing(fn(User $record): int => $record->payoutBalance()['outstanding_cents']),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstructors::route('/'),
            'view' => Pages\ViewInstructor::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PayoutHistoryRelationManager::class,
        ];
    }

    /** Filter a User query to instructors only. */
    public static function scopeToInstructors(Builder $query): Builder
    {
        return $query->where('role', UserRole::Instructor->value);
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SmsMessageResource\Pages;
use App\Models\SmsMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SmsMessageResource extends Resource
{
    protected static ?string $model = SmsMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    
    protected static ?string $navigationLabel = 'Conversations SMS';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('phone_number'),
                Forms\Components\TextInput::make('role'),
                Forms\Components\Textarea::make('content')
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('created_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('phone_number')
                    ->searchable()
                    ->sortable()
                    ->label('Téléphone'),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'user' => 'gray',
                        'assistant' => 'success',
                        default => 'gray',
                    })
                    ->label('Auteur'),
                Tables\Columns\TextColumn::make('content')
                    ->limit(100)
                    ->wrap()
                    ->label('Message'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Date'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSmsMessages::route('/'),
            'view' => Pages\ViewSmsMessage::route('/{record}'),
        ];
    }
}

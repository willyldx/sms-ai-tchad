<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Utilisateurs SMS';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nom')
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('password')
                    ->label('Mot de passe')
                    ->password()
                    ->revealable()
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create'),
                Forms\Components\Toggle::make('is_admin')
                    ->label('Accès admin')
                    ->default(false),
                Forms\Components\TextInput::make('phone_number')
                    ->tel()
                    ->label('Numéro de téléphone')
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('daily_requests_count')
                    ->numeric()
                    ->default(0),
                Forms\Components\DatePicker::make('daily_requests_date')
                    ->label('Date quota')
                    ->native(false),
                Forms\Components\DateTimePicker::make('last_sms_received_at')
                    ->label('Dernier SMS reçu')
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_admin')
                    ->boolean()
                    ->label('Admin')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('Numéro de téléphone')
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('daily_requests_count')
                    ->label('Requêtes (Jour)')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 3 => 'danger',
                        $state >= 1 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('daily_requests_date')
                    ->label('Date quota')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_sms_received_at')
                    ->label('Dernier SMS')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Inscrit le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}

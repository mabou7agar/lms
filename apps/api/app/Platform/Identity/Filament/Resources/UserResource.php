<?php

namespace App\Platform\Identity\Filament\Resources;

use App\Platform\Identity\Filament\Resources\UserResource\Pages;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Services\ImpersonationManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Admin resource for users. Read/manage account state (not passwords/MFA secrets).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Identity';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            TextInput::make('phone')->tel()->maxLength(20),
            Select::make('locale')->options(['en' => 'English', 'ar' => 'العربية'])->default('en'),
            // Role assignment via the spatie roles relationship (HasRoles on User).
            Select::make('roles')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->label('Roles'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->toggleable(),
                TextColumn::make('roles.name')->badge()->label('Roles')->toggleable(),
                IconColumn::make('is_active')->boolean(),
                IconColumn::make('mfa_enabled')->boolean()->label('MFA'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                // Orchestration only: ImpersonationManager owns the guards, the guard switch, and
                // the audit trail. Visibility mirrors UserPolicy::impersonate (off for self and
                // super_admin rows); the manager still re-checks below the gate.
                Action::make('impersonate')
                    ->label('Impersonate')
                    ->icon('heroicon-o-identification')
                    ->requiresConfirmation()
                    ->modalDescription('Sign in as this user to reproduce their view. This is audited, and a banner will show until you leave.')
                    ->visible(function (User $record): bool {
                        $actor = Auth::user();

                        return $actor instanceof User && $actor->can('impersonate', $record);
                    })
                    ->action(function (User $record) {
                        try {
                            app(ImpersonationManager::class)->start($record);

                            return redirect()->to('/admin');
                        } catch (Throwable $e) {
                            Notification::make()->title('Cannot impersonate')->body($e->getMessage())->danger()->send();

                            return null;
                        }
                    }),
            ]);
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

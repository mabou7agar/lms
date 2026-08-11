<?php

namespace App\Platform\AI\Filament\Resources;

use App\Platform\AI\Filament\Resources\AiPromptResource\Pages;
use App\Platform\AI\Models\AiPrompt;
use App\Platform\AI\Prompts\PromptLibrary;
use App\Platform\Identity\Contracts\Actor;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The prompt-library admin: list, create, edit, preview, duplicate, and version/rollback prompts.
 * Each version is immutable history; "Duplicate" mints the next draft version and "Make active"
 * (rollback) flips which version a run resolves. Admin/super-admin gated.
 */
class AiPromptResource extends Resource
{
    protected static ?string $model = AiPrompt::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'AI Prompts';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static ?string $recordTitleAttribute = 'key';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof Actor && $user->hasRole(['admin', 'super_admin']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->columns(2)->schema([
                TextInput::make('key')->required()->maxLength(120)
                    ->disabledOn('edit')
                    ->helperText('Stable machine key, e.g. "tutor.explain". Immutable after creation.'),
                TextInput::make('version')->numeric()->default(1)->minValue(1)
                    ->disabledOn('edit')
                    ->helperText('Version number. Use "Duplicate" to create the next draft version.'),
                TextInput::make('purpose')->maxLength(255)->columnSpanFull(),
                TextInput::make('locale')->default('en')->maxLength(8),
                TextInput::make('model_preference')->maxLength(120)
                    ->placeholder('provider:model')
                    ->helperText('Optional. "provider:model" (e.g. openai:gpt-4o-mini), "model", or empty for the default.'),
            ]),

            Section::make('Templates')->schema([
                Textarea::make('system_prompt')->rows(4)->columnSpanFull()
                    ->helperText('Trusted system instructions. Not passed through the injection guard.'),
                Textarea::make('user_template')->required()->rows(6)->columnSpanFull()
                    ->helperText('User message template. Use {{ variable }} placeholders.'),
                TagsInput::make('variables')->columnSpanFull()
                    ->helperText('Declared variable names used in the templates.'),
                Toggle::make('active')->helperText('Only one version per (key, locale) should be active.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')->badge()->searchable()->sortable(),
                TextColumn::make('version')->sortable(),
                TextColumn::make('locale')->badge()->toggleable(),
                IconColumn::make('active')->boolean(),
                TextColumn::make('purpose')->limit(40)->toggleable(),
                TextColumn::make('updated_at')->dateTime()->since()->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('active'),
            ])
            ->recordActions([
                EditAction::make(),
                self::previewAction(),
                self::duplicateAction(),
                self::rollbackAction(),
                DeleteAction::make(),
            ]);
    }

    /** Render the prompt (empty variables) in a modal so an editor can eyeball it. */
    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->icon('heroicon-o-eye')
            ->modalHeading('Prompt preview')
            ->modalSubmitAction(false)
            ->modalContent(function (AiPrompt $record): HtmlString {
                $system = e((string) $record->system_prompt);
                $user = e($record->user_template);

                return new HtmlString(
                    '<div class="space-y-3 text-sm">'
                    .'<div><strong>System</strong><pre class="mt-1 whitespace-pre-wrap">'.$system.'</pre></div>'
                    .'<div><strong>User template</strong><pre class="mt-1 whitespace-pre-wrap">'.$user.'</pre></div>'
                    .'</div>'
                );
            });
    }

    /** Duplicate this version into a new inactive draft version. */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->icon('heroicon-o-document-duplicate')
            ->requiresConfirmation()
            ->modalDescription('Create the next version as an inactive draft copy of this one?')
            ->action(function (AiPrompt $record): void {
                try {
                    $copy = app(PromptLibrary::class)->duplicate($record->key, $record->version, $record->locale);
                    Notification::make()->title("Duplicated to version {$copy->version}")->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Duplicate failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Make this version the active one for its (key, locale) — i.e. publish / rollback. */
    public static function rollbackAction(): Action
    {
        return Action::make('rollback')
            ->label('Make active')
            ->icon('heroicon-o-arrow-uturn-left')
            ->requiresConfirmation()
            ->visible(fn (AiPrompt $record): bool => ! $record->active)
            ->modalDescription('Make this version the active one and deactivate the others for this key + locale?')
            ->action(function (AiPrompt $record): void {
                try {
                    app(PromptLibrary::class)->activate($record->key, $record->version);
                    Notification::make()->title("Version {$record->version} is now active")->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Rollback failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiPrompts::route('/'),
            'create' => Pages\CreateAiPrompt::route('/create'),
            'edit' => Pages\EditAiPrompt::route('/{record}/edit'),
        ];
    }
}

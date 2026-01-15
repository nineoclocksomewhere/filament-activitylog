<?php

namespace Rmsramos\Activitylog\Resources\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Rmsramos\Activitylog\ActivitylogPlugin;

class ActivitylogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextInput::make('causer_id')
                        ->afterStateHydrated(function ($component, ?Model $record) {
                            if (!$record?->causer) {
                                return $component->state('-');
                            }

                            $causer = $record->causer;
                            $name = [];

                            if (isset($causer->firstname)) {
                                $name[] = $causer->firstname;
                            }

                            if (isset($causer->name)) {
                                $name[] = $causer->name;
                            }

                            return $component->state(!empty($name) ? implode(' ', $name) : '-');
                        })
                        ->label(__('activitylog::forms.fields.causer.label')),

                    TextInput::make('subject_type')
                        ->afterStateHydrated(function ($component, ?Model $record, $state) {
                            /** @var Activity $record */
                            return $state ? $component->state(Str::of($state)->afterLast('\\')->headline().' # '.$record->subject_id) : $component->state('-');
                        })
                        ->label(__('activitylog::forms.fields.subject_type.label')),

                    Textarea::make('description')
                        ->label(__('activitylog::forms.fields.description.label'))
                        ->rows(2)
                        ->columnSpan('full'),
                ]),

                Section::make([
                    TextEntry::make('log_name')
                        ->state(function (?Model $record): string {
                            /** @var Activity $record */
                            return $record?->log_name ? ucwords($record->log_name) : '-';
                        })
                        ->label(__('activitylog::forms.fields.log_name.label')),

                    TextEntry::make('event')
                        ->state(function (?Model $record): string {
                            /** @var Activity $record */
                            return $record?->event ? ucwords(__('activitylog::action.event.'.$record->event)) : '-';
                        })
                        ->label(__('activitylog::forms.fields.event.label')),

                    ViewField::make('properties')
                        ->formatStateUsing(function ($state, ?Model $record) {
                            /** @var Activity|null $record */
                            return $record?->properties?->toArray() ?? [];
                        })
                        ->view('activitylog::filament.tables.columns.activity-logs-properties')
                        ->label(__('activitylog::forms.fields.properties.label')),

                    TextEntry::make('created_at')
                        ->label(__('activitylog::forms.fields.created_at.label'))
                        ->state(function (?Model $record): string {
                            /** @var Activity $record */
                            if (! $record?->created_at) {
                                return '-';
                            }

                            $parser = ActivitylogPlugin::get()->getDateParser();

                            return $parser($record->created_at)
                                ->format(ActivitylogPlugin::get()->getDatetimeFormat());
                        }),
                ]),
            ]);
    }
}

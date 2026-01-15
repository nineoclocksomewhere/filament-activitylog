<?php

namespace Rmsramos\Activitylog\Actions\Concerns;

use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Filament\Actions\StaticAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Rmsramos\Activitylog\ActivitylogPlugin;
use Rmsramos\Activitylog\Infolists\Components\TimeLineIconEntry;
use Rmsramos\Activitylog\Infolists\Components\TimeLinePropertiesEntry;
use Rmsramos\Activitylog\Infolists\Components\TimeLineRepeatableEntry;
use Rmsramos\Activitylog\Infolists\Components\TimeLineTitleEntry;
use Spatie\Activitylog\Models\Activity;

trait ActionContent
{
    protected ?array $withRelations = null;

    protected ?array $timelineIcons = [
        'created'  => 'heroicon-m-plus',
        'updated'  => 'heroicon-m-pencil-square',
        'deleted'  => 'heroicon-m-trash',
        'restored' => 'heroicon-m-arrow-uturn-left',
    ];

    protected ?array $timelineIconColors = [
        'created'  => 'success',
        'updated'  => 'warning',
        'deleted'  => 'danger',
        'restored' => 'info',
    ];

    protected ?int $limit = 10;

    protected Closure $modifyQueryUsing;

    protected Closure|Builder $query;

    protected ?Closure $activitiesUsing;

    protected ?Closure $modifyTitleUsing;

    protected ?Closure $shouldModifyTitleUsing;

    public static function getDefaultName(): ?string
    {
        return 'activitylog_timeline';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInfolist();
        $this->configureModal();
        $this->activitiesUsing        = null;
        $this->modifyTitleUsing       = null;
        $this->shouldModifyTitleUsing = fn () => true;
        $this->modifyQueryUsing       = fn ($builder) => $builder;
        $this->modalHeading           = __('activitylog::action.modal.heading');
        $this->modalDescription       = __('activitylog::action.modal.description');

        $this->query = function (?Model $record) {
            if (! $record) {
                return Activity::query()->whereRaw('1 = 0');
            }

            return Activity::query()
                ->with([
                    'subject' => function ($query) {
                        if (method_exists($query, 'withTrashed')) {
                            $query->withTrashed();
                        }
                    },
                    'causer',
                ])
                ->where(function (Builder $query) use ($record) {
                    $query->where('subject_type', $record->getMorphClass())
                        ->where('subject_id', $record->getKey());

                    if ($relations = $this->getWithRelations()) {
                        foreach ($relations as $relation) {
                            if (method_exists($record, $relation)) {
                                try {
                                    $relationInstance = $record->{$relation}();

                                    if ($relationInstance instanceof BelongsToMany) {
                                        $subjectType = $relationInstance->getPivotClass();
                                        $relatedIds  = $relationInstance->pluck($relationInstance->getTable().'.id')->toArray();

                                        if (! empty($relatedIds)) {
                                            $query->orWhere(function (Builder $q) use ($subjectType, $relatedIds) {
                                                $q->where('subject_type', $subjectType)
                                                    ->whereIn('subject_id', $relatedIds);
                                            });
                                        }

                                        continue;
                                    }

                                    $relatedModel     = $relationInstance->getRelated();
                                    $relatedIds       = $relationInstance->pluck('id')->toArray();

                                    if (! empty($relatedIds)) {
                                        $query->orWhere(function (Builder $q) use ($relatedModel, $relatedIds) {
                                            $q->where('subject_type', $relatedModel->getMorphClass())
                                                ->whereIn('subject_id', $relatedIds);
                                        });
                                    }
                                } catch (\Exception $e) {
                                    // Ignore errors
                                }
                            }
                        }
                    }
                });
        };
    }

    protected function configureInfolist(): void
    {
        $this->infolist(function (?Model $record, Infolist $infolist) {
            $activities = $this->getActivities($record, $this->getWithRelations());

            return $infolist
                ->state(['activities' => $activities])
                ->schema($this->getSchema());
        });
    }

    protected function configureModal(): void
    {
        $this->slideOver()
            ->modalIcon('heroicon-o-eye')
            ->modalFooterActions(fn () => [])
            ->tooltip(__('activitylog::action.modal.tooltip'))
            ->icon('heroicon-o-bell-alert');
    }

    public function getSchema(Schema $schema): ?Schema
    {
        return $schema->schema([
            TimeLineRepeatableEntry::make('activities')
                ->schema([
                    TextEntry::make('causer.name')
                        ->state(function ($record) {
                            if (!$record->causer) {
                                return '-';
                            }

                            $causer = $record->causer;
                            $name = [];

                            if (isset($causer->firstname)) {
                                $name[] = $causer->firstname;
                            }

                            if (isset($causer->name)) {
                                $name[] = $causer->name;
                            }
                            clLog()->log(implode(' ', $name));
                            return !empty($name) ? implode(' ', $name) : '-';
                        })
                        ->label(__('activitylog::forms.fields.causer.label')),

                    TextEntry::make('subject_type')
                        ->state(function ($record) {
                            if (!$record->subject_type) {
                                return '-';
                            }

                            $modelName = Str::of($record->subject_type)->afterLast('\\')->headline();
                            return $modelName . ' # ' . $record->subject_id;
                        })
                        ->label(__('activitylog::forms.fields.subject_type.label')),

                    TextEntry::make('event')
                        ->state(function (?Model $record): string {
                            /** @var Activity $record */
                            return $record?->event ? ucwords(__('activitylog::action.event.'.$record->event)) : '-';
                        })
                        ->label(__('activitylog::forms.fields.event.label')),

                    TextEntry::make('description')
                        ->state(fn ($record) => $record->description ?? null)
                        ->label(__('activitylog::forms.fields.description.label')),

                    TextEntry::make('properties')
                        ->state(function ($record) {
                            if (!$record->properties) {
                                return null;
                            }

                            $properties = is_string($record->properties)
                                ? json_decode($record->properties, true)
                                : $record->properties;

                            return $properties ?? null;
                        })
                        ->view('activitylog::filament.tables.columns.activity-logs-properties')
                        ->label(__('activitylog::forms.fields.properties.label')),

                    TextEntry::make('log_name')
                        ->state(fn ($record) => $record->log_name ?? null)
                        ->hiddenLabel()
                        ->badge(),

                    TextEntry::make('updated_at')
                        ->state(fn ($record) => $record->updated_at ?? null)
                        ->hiddenLabel()
                        ->since()
                        ->badge(),

                    TextEntry::make('separator')
                        ->state('<hr class="border-gray-200 dark:border-gray-700 my-4">')
                        ->hiddenLabel()
                        ->html(),
                ]),
        ]);
    }

    public function withRelations(?array $relations = null): ?StaticAction
    {
        $this->withRelations = $relations;

        return $this;
    }

    public function limit(?int $limit = 10): ?StaticAction
    {
        $this->limit = $limit;

        return $this;
    }

    public function query(Closure|Builder|null $query): static
    {
        $this->query = $query;

        return $this;
    }

    public function modifyQueryUsing(Closure $closure): static
    {
        $this->modifyQueryUsing = $closure;

        return $this;
    }

    public function modifyTitleUsing(Closure $closure): static
    {
        $this->modifyTitleUsing = $closure;

        return $this;
    }

    public function shouldModifyTitleUsing(Closure $closure): static
    {
        $this->shouldModifyTitleUsing = $closure;

        return $this;
    }

    public function activitiesUsing(Closure $closure): static
    {
        $this->activitiesUsing = $closure;

        return $this;
    }

    public function getWithRelations(): ?array
    {
        return $this->evaluate($this->withRelations);
    }

    public function getTimelineIcons(): ?array
    {
        return $this->evaluate($this->timelineIcons);
    }

    public function getTimelineIconColors(): ?array
    {
        return $this->evaluate($this->timelineIconColors);
    }

    public function getLimit(): ?int
    {
        return $this->evaluate($this->limit);
    }

    public function getQuery(?Model $record = null): ?Builder
    {
        return $this->evaluate($this->query, ['record' => $record]);
    }

    public function getModifyQueryUsing(Builder $builder): Builder
    {
        return $this->evaluate($this->modifyQueryUsing, ['builder' => $builder]);
    }

    public function getActivitiesUsing(): ?Collection
    {
        return $this->evaluate($this->activitiesUsing);
    }

    protected function getActivities(?Model $record, ?array $relations = null): Collection
    {
        if ($activities = $this->getActivitiesUsing()) {
            return $activities;
        }

        if (! $record) {
            return collect();
        }

        $builder = $this->getQuery($record)
            ->latest()
            ->limit($this->getLimit());

        return $this->getModifyQueryUsing($builder)->get();
    }

    protected function formatActivityData($activity): array
    {
        $properties = [];

        if ($activity->properties) {
            if (is_string($activity->properties)) {
                $properties = json_decode($activity->properties, true) ?? [];
            } elseif (is_array($activity->properties)) {
                $properties = $activity->properties;
            } elseif (is_object($activity->properties) && method_exists($activity->properties, 'toArray')) {
                $properties = $activity->properties->toArray();
            }
        }

        if ($activity->event === 'restored') {
            if (empty($properties) && $activity->description !== 'restored') {
                $properties['description'] = $activity->description;
            }
        }

        return [
            'log_name'    => $activity->log_name,
            'description' => $activity->description,
            'subject'     => $activity->subject,
            'event'       => $activity->event,
            'causer'      => $activity->causer,
            'properties'  => $this->formatDateValues($properties),
            'batch_uuid'  => $activity->batch_uuid,
            'created_at'  => $activity->created_at,
            'updated_at'  => $activity->updated_at,
        ];
    }

    protected static function formatDateValues(array|string|null $value): array|string|null
    {
        if (is_null($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as &$item) {
                $item = self::formatDateValues($item);
            }

            return $value;
        }

        if (is_numeric($value) && ! preg_match('/^\d{10,}$/', $value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        try {
            $parser = ActivitylogPlugin::get()->getDateParser();

            return $parser($value)->format(ActivitylogPlugin::get()->getDatetimeFormat());
        } catch (InvalidFormatException $e) {
            return $value;
        } catch (\Exception $e) {
            return $value;
        }
    }
}

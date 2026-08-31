<?php

namespace App\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Immutable-ish description of something worth telling people about.
 *
 * Modules build one of these and hand it to the NotificationService; they never
 * decide recipients' channels, templates or delivery mechanics themselves.
 */
class NotificationMessage
{
    public string $type;

    public ?int $farmId = null;

    /** @var list<int> */
    public array $userIds = [];

    /** Include every farm member holding at least one of these permissions. */
    public array $permissions = [];

    public ?string $title = null;

    public ?string $body = null;

    public ?string $actionUrl = null;

    public ?string $actionLabel = null;

    public ?string $sourceType = null;

    public ?int $sourceId = null;

    public ?int $instanceId = null;

    public ?string $section = null;

    public array $payload = [];

    public ?string $priority = null;

    public ?string $dedupeKey = null;

    public ?Carbon $availableAt = null;

    /** Variables merged into the email template on top of the derived set. */
    public array $templateData = [];

    /** Skip these user ids even if they match a permission group. */
    public array $excludeUserIds = [];

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function make(string $type): self
    {
        return new self($type);
    }

    public function farm(int|Model|null $farm): self
    {
        $this->farmId = $farm instanceof Model ? (int) $farm->getKey() : $farm;

        return $this;
    }

    public function to(int|Model|null ...$users): self
    {
        foreach ($users as $user) {
            if ($user === null) {
                continue;
            }
            $id = $user instanceof Model ? (int) $user->getKey() : (int) $user;
            if ($id > 0) {
                $this->userIds[] = $id;
            }
        }

        $this->userIds = array_values(array_unique($this->userIds));

        return $this;
    }

    /**
     * @param  iterable<int|Model>  $users
     */
    public function toMany(iterable $users): self
    {
        foreach ($users as $user) {
            $this->to($user);
        }

        return $this;
    }

    public function toFarmMembersWithPermission(string ...$permissions): self
    {
        $this->permissions = array_values(array_unique([...$this->permissions, ...$permissions]));

        return $this;
    }

    public function except(int|Model|null ...$users): self
    {
        foreach ($users as $user) {
            if ($user === null) {
                continue;
            }
            $this->excludeUserIds[] = $user instanceof Model ? (int) $user->getKey() : (int) $user;
        }

        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function body(?string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function action(?string $url, ?string $label = null): self
    {
        $this->actionUrl = $url;
        $this->actionLabel = $label;

        return $this;
    }

    public function source(?Model $model): self
    {
        if ($model) {
            $this->sourceType = $model::class;
            $this->sourceId = (int) $model->getKey();
        }

        return $this;
    }

    public function taskInstance(int|Model|null $instance): self
    {
        if ($instance === null) {
            return $this;
        }

        $this->instanceId = $instance instanceof Model ? (int) $instance->getKey() : (int) $instance;

        if ($instance instanceof Model) {
            $this->source($instance);
        }

        return $this;
    }

    public function section(?string $section): self
    {
        $this->section = $section;

        return $this;
    }

    public function payload(array $payload): self
    {
        $this->payload = array_merge($this->payload, $payload);

        return $this;
    }

    public function priority(?string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Logical identity of the event. The recipient id is appended by the
     * service, so callers only describe the event itself.
     */
    public function dedupe(?string $key): self
    {
        $this->dedupeKey = $key;

        return $this;
    }

    public function availableAt(?Carbon $moment): self
    {
        $this->availableAt = $moment;

        return $this;
    }

    public function with(array $templateData): self
    {
        $this->templateData = array_merge($this->templateData, $templateData);

        return $this;
    }
}

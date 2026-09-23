# aeroflow-playback

Сервис очереди воспроизведения AeroFlow. Принимает integration command
`RequestAnnouncementPlayback` от `aeroflow-core` и превращает её в `PlaybackJob`.

Сервис **не знает** предметную область рейсов: для него существует только
`PlaybackJob` с готовой аудиопоследовательностью. Подробнее — в
`aeroflow-docs/architecture.md` и `aeroflow-docs/domain-model.md`.

## Текущий срез (задача 013)

Первый тонкий срез интеграции Core → Playback:

* подписка на RabbitMQ-очередь `announcement_playback`, в которую публикует Core;
* `HandleAnnouncementPlaybackRequest` создаёт `PlaybackJob` в статусе `Pending`;
* идемпотентность по `messageId` (уникальный `source_message_id`): повторная
  доставка не создаёт дубликат.

Вне среза: приоритетная очередь, прерывание, emergency mode, повторы,
`CancelAnnouncementPlayback`, обратные события в core и `aeroflow-agent`.

## Контракт

`RequestAnnouncementPlayback` приходит как JSON. Playback декодирует тело
собственным transport-сериализатором (`AnnouncementPlaybackSerializer`) в свой DTO
и не зависит от PHP-классов Core. Поля: `messageId`, `correlationId`,
`announcementId`, `type`, `priority`, `audioSequence`, `repeatRule`, `occurredAt`,
`schemaVersion`.

## Запуск

Брокер общий с `aeroflow-core` (Core публикует RabbitMQ на порт 5672 хоста).
Сначала поднимите core, затем:

```bash
docker compose up -d --build
docker compose exec aeroflow_playback_app php bin/console doctrine:database:create --if-not-exists
docker compose exec aeroflow_playback_app php bin/console doctrine:migrations:migrate --no-interaction
```

Воркер `aeroflow_playback_worker` выполняет
`messenger:consume announcement_playback agent_events scheduler`.

## Тесты

```bash
php vendor/bin/phpunit
```

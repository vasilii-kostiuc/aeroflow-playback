<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playback\Infrastructure\Messaging;

use App\Playback\Application\Agent\AgentPlaybackEvent;
use App\Playback\Application\Agent\PlayAudioSequence;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Infrastructure\Messaging\AgentCommandSerializer;
use App\Playback\Infrastructure\Messaging\AgentEventSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Uid\Uuid;

final class AgentSerializersTest extends TestCase
{
    public function testCommandSerializerEncodesNeutralContract(): void
    {
        $job = PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'check_in_opening',
            100,
            $sequence = [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
        );

        $encoded = new AgentCommandSerializer()->encode(new Envelope(PlayAudioSequence::for($job)));

        $body = json_decode($encoded['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('audio.play_sequence', $body['command']);
        self::assertSame($job->getId()->toRfc4122(), $body['jobId']);
        self::assertSame($sequence, $body['audioSequence']);
        self::assertSame(1, $body['schemaVersion']);
        self::assertSame(
            ['command', 'messageId', 'jobId', 'audioSequence', 'occurredAt', 'schemaVersion'],
            array_keys($body),
        );
    }

    public function testEventSerializerDecodesAgentEvent(): void
    {
        $jobId = Uuid::v7()->toRfc4122();

        $envelope = new AgentEventSerializer()->decode(['body' => json_encode([
            'event' => 'audio.playback_failed',
            'messageId' => Uuid::v7()->toRfc4122(),
            'jobId' => $jobId,
            'reason' => 'player exited 1',
            'occurredAt' => '2026-07-10T10:00:00+00:00',
            'schemaVersion' => 1,
        ], JSON_THROW_ON_ERROR)]);

        $message = $envelope->getMessage();
        self::assertInstanceOf(AgentPlaybackEvent::class, $message);
        self::assertSame(AgentPlaybackEvent::FAILED, $message->event);
        self::assertSame($jobId, $message->jobId);
        self::assertSame('player exited 1', $message->reason);
    }

    public function testEventSerializerRejectsBodyWithoutDiscriminator(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        new AgentEventSerializer()->decode(['body' => json_encode([
            'messageId' => Uuid::v7()->toRfc4122(),
            'jobId' => Uuid::v7()->toRfc4122(),
        ], JSON_THROW_ON_ERROR)]);
    }
}

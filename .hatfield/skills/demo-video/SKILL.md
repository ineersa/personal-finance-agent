---
name: demo-video
description: "Use when planning, recording, narrating, assembling, or verifying this project's demo or hackathon video. Covers Playwright CLI capture, ElevenLabs narration, FFmpeg assembly, and a 90-second delivery budget."
---

# Demo video production

Produce a short, readable demonstration of real working functionality. Default to a maximum of 90 seconds unless the owner specifies another limit. Prefer 3–5 scenes and a small repeatable process, not a general film-production framework.

This skill describes the process. Keep the specific script, shot list, and media separate. Follow AGENTS.md and the task-workflow skill for application changes and tracked development; this skill does not authorize installations, service startup, paid API calls, resets, publishing, or extra tests.

## Walkthrough references

- [October Visa import, chart, and history follow-up](references/october-walkthrough.md): exact CSV, candidate prompts, scene sequence, prerequisites, and verification limits. Read before rehearsing this scenario.
- [Approved narration](references/narration.md): owner-approved wording and per-scene voiceover delivery instructions. This supersedes earlier narration candidates in the walkthrough.

## 1. Check prerequisites

Check installed versions and command help before relying on syntax:

```bash
playwright-cli --version
playwright-cli --help open
playwright-cli --help video-start
playwright-cli --help video-stop
ffmpeg -version
ffprobe -version
```

Check available encoders/filters with `ffmpeg -hide_banner -encoders` and `ffmpeg -hide_banner -filters`. Required capabilities are H.264/AAC export, text/subtitle rendering, concatenation, and any planned fades. Check a readable installed font and free disk space.

Observed on 2026-11-06 (recheck when needed):
- Playwright CLI `1.59.0-alpha-1771104257000` exposes `video-start` and `video-stop --filename`.
- Google Chrome is installed at `/usr/bin/google-chrome`.
- FFmpeg/ffprobe 6.1.1 are installed; libx264, AAC, drawtext, subtitles, concat, fade, and afade are available.
- Node 22.20.0, npm 11.6.2, and Noto Sans are available.
- Shotcut is optional, not required. No MCP integration is required.

These are capability checks, NOT proof of successful capture or export. Before a real shoot, make a short disposable recording of harmless content, convert it, and inspect the output. Do not silently install an alternative recorder or custom browser runner if the CLI fails.

### Playwright-managed FFmpeg prerequisite

The system FFmpeg is for assembly; Playwright also needs its own matching recorder binary. On this installation, missing `~/.cache/ms-playwright/ffmpeg-1011/ffmpeg-linux` caused `video-start` to report success but `video-stop` to fail with “Video recording has not been started.” The installed CLI swallows the underlying start error. An awaited `page.video().start()` through CLI `run-code` on a harmless page exposed the missing executable; do not use private OAuth pages for diagnosis.

The matching binary was installed on 2026-11-06 using the Playwright package bundled with the installed CLI:

```bash
node /home/ineersa/.nvm/versions/node/v22.20.0/lib/node_modules/@playwright/cli/node_modules/playwright/cli.js install ffmpeg
```

This is the observed local installation path, not a portable command: resolve the current installed CLI/package first on another machine or version. Avoid an unrelated `npx` package version. Linux Mint downloaded the Ubuntu 24.04 fallback build. Installation succeeded; capture and export still require their own runtime verification.

## 2. Plan the story before filming

Agree on one takeaway and demonstrate only currently working features. Use a separate shot list with columns for scene, browser actions, narration, heading, target duration, and output filename. Roughly 170–190 words is an initial narration budget, not proof that the video fits.

Reserve time for an opening and closing. Prefer one strong workflow and its useful result over a tour of every screen. Do not invent feature claims or stage fake successful results.

Choose a media directory outside the Git checkout. Keep generated footage, audio, profiles, authentication state, and exports out of Git. Keep reusable instructions and an approved non-sensitive script distinct from generated artifacts.

## 3. Prepare safe recording conditions

- Read the project's README and TESTING.md before preparing an isolated live stack. Use checkout-scoped Castor lifecycle tasks and their printed URLs, never guessed ports or Compose names.
- Verify storage and stack ownership before any fixture operation. Use synthetic canonical fixtures and test-owned storage. Do not invoke the Settings reset on the owner's preview, purge owner data, or stop unrelated services.
- Record actual worker/broker/Mercure behavior against an isolated running stack when those workflows appear in the story. Intercepted tests do not establish that integration.
- Avoid exposing credentials, personal statements, provider payloads, notification popups, or private browser tabs. Authenticate before the useful footage where possible; inspect the recording to ensure no secrets were captured.
- Use a unique browser session and isolated profile. Never attach to the owner's personal browser or use global browser cleanup.
- Pick a consistent viewport and capture size, preferably 1920×1080 when the UI remains readable. Verify the actual recorded dimensions; resizing a viewport does not prove video resolution. Avoid tiny UI and unnecessary upscaling.

## 4. Record through the installed CLI

Load the `playwright-cli` skill at `/home/ineersa/.agents/skills/playwright-cli/SKILL.md`, including its session-management and video-recording references. Load the subagents skill before delegation. Use the named `browser` subagent for browser interaction and visual inspection; obey the active delegation rules. Main owns setup, media processing, and saved QA commands.

Run headlessly unless the owner explicitly asks for a visible browser. Assign a unique session name to every command. The installed CLI's help is authoritative over examples in reference material.

Capture sequence (placeholders, not a command to run blindly):

```bash
playwright-cli -s=<unique-session> open <isolated-demo-url> --browser=chrome
# Inspect the snapshot, authenticate safely, and frame the first scene.
playwright-cli -s=<unique-session> video-start
# Perform the approved scene using current snapshot refs.
playwright-cli -s=<unique-session> video-stop --filename=<absolute-media-path>/scene-01.webm
playwright-cli -s=<unique-session> close
```

Check recording start/stop behavior in the disposable probe before relying on it. Do not assume every video command works merely because help lists it. Refresh snapshots when refs become stale. Keep actions deliberate and give results time to become readable. Use real state/element readiness rather than fixed sleeps for application completion; intentional holds are for pacing only.

Record separate scenes when this makes reshoots easier. Note useful cut points. Remove long waits in editing without implying unrealistic real-time performance. If an application error occurs, report it and discard that take rather than hiding it as a successful workflow. Do not expand into negative-path or special-case testing without permission.

Stop recording and close only owned sessions in cleanup, including failure paths. Do not install Node Playwright or write a custom recording runner merely to reproduce Laurent's example.

## 5. Narration and timing

ElevenLabs website downloads are sufficient for one video. API automation is optional and requires explicit scope and authorized credential handling; never ask for secrets in chat or place them in committed files. Do not assume an account, voice, credits, or usage rights exist. Use an authorized voice, not an unauthorized impersonation.

Prefer one narration file per scene. Reuse unchanged audio instead of generating it repeatedly. Generate narration before final capture pacing or assembly, then measure actual durations with ffprobe. Do not estimate final timing solely from text length.

Example duration inspection:

```bash
ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 <audio-file>
```

Leave room for pauses and the closing. If narration overruns, shorten the script rather than making speech or UI actions uncomfortably fast. Send only approved non-sensitive narration to external services.

## 6. Assemble with FFmpeg

Use FFmpeg for trimming, joining, headings, subtitles, narration, and export. Shotcut is an optional visual editor, not a prerequisite. Do not introduce a new orchestration framework or application endpoints for video production.

- Normalize scene dimensions, frame rate, and compatible audio parameters before joining.
- Align each narration clip to its scene; ensure speech is not cut off or overlapping unintentionally.
- Use a few short headings. Keep overlays away from important controls, charts, and results.
- Create subtitles from the final narration and timeline. A sidecar VTT is not embedded captions; burn captions in only when appropriate for the delivery platform.
- Keep music optional, licensed, and quiet enough not to obscure speech.
- Export a broadly compatible MP4: H.264 video, AAC audio, yuv420p pixel format, and fast-start metadata. Prefer 1080p with readable content, not upscaled low-resolution capture.

Build the FFmpeg command for the actual inputs. Verify it on a short sample before processing the full film. Keep shell quoting safe for caption text and filenames. Never truncate the ending just to satisfy the duration limit.

## 7. Verify and hand off

Use ffprobe to inspect final duration, dimensions, frame rate, and codecs. Check the complete exported file, not just source clips:
- At or below the agreed duration, including the closing.
- Correct scene order, no black gaps, broken UI, or leaked private information.
- Readable UI/headings/subtitles and consistent framing.
- Narration audible, synchronized, and complete; no clipped words.
- Feature claims match what actually appears on screen.

Use the browser subagent for visual playback inspection when needed. If audio playback/listening cannot be verified with available tools, explicitly report that limitation; metadata and waveforms do not prove intelligibility.

Report the output path, measured duration/resolution/codecs, checks actually performed, and remaining limitations. Ask before uploading or publishing. Close owned browsers and stop only task-owned stacks when finished; stop the shared proxy only through the documented project task.

## Inspiration and limits

Laurent Marquet's pipeline:
https://github.com/975L/SiteBundle#how-the-c975l-films-are-shot

It describes scripted browser steps, per-step ElevenLabs narration, cached speech, and generated video/subtitles/posters. The full production camera is private. The public Node example is a mute recording starter, not a complete narration/assembly tool. Borrow the production pattern without installing SiteBundle, copying its reset/account commands, or adding guided-project endpoints to this application.

---
name: community-code-podcast
description: >
  Generates all publishing assets for the Community + Code podcast
  (communitycode.dev), hosted by Chris Reynolds. Use this skill whenever a
  podcast transcript is provided, or whenever Chris asks about episode content,
  show notes, blog posts, or social posts for Community + Code. Also use this
  skill when Chris asks technical questions about the podcast WordPress site
  with no transcript attached. The skill is the go-to for anything
  Community + Code related — episode processing, site questions, content
  generation, you name it.
---

# Community + Code Podcast Skill

Community + Code (communitycode.dev) is a podcast about the humans behind
developer communities — hosted by Chris Reynolds (jazzsequence). It's not
about code; it's about people. The show spotlights developers, open source
contributors, and community builders, exploring their stories, passions, and
the communities they've shaped. Tagline: "the people behind the commits."

**Voice and tone throughout all assets:** Casual, warm, and developer-friendly.
Non-technical. Humanistic. Occasionally funny or quirky — but genuine, not
forced. Never dry or corporate. Think: a developer talking to a friend about
a fascinating conversation they just had.

---

## Detecting the Request Type

**If a transcript is attached or uploaded:** Process the episode (see "Episode
Processing" below). The episode name is the guest's full name, taken directly
from the transcript.

**If a folder is mounted and no transcript is provided:** First, look in the
mounted folder for a guest episode subfolder — transcripts may be there and can
be read directly. Episode folders are named by guest name only (e.g.,
`Fons Vandamme/`). If you also have access to the host filesystem, you may
additionally check `~/Documents/Community + Code/` as a fallback location
(e.g., `~/Documents/Community + Code/Fons Vandamme/`).

**If no transcript is attached or found:** The conversation is likely a
technical or editorial question about the communitycode.dev WordPress site.
When the site repo is accessible, prefer reading relevant source files from the
current working copy (the project/repo root that contains this skill file)
before answering — don't just reason about a generic WordPress site when the
actual code is available. If the current working copy is not available but you
do have access to the host filesystem, you may optionally use
`~/pantheon-local-copies/communitycodedev` as a fallback. Look at the theme,
plugins, and configuration files relevant to the question.

---

## Transcript Formats

Transcripts may be provided as `.txt` or `.vtt` (WebVTT) files. Both work fine.

For `.vtt` files, ignore the timestamp lines (e.g., `00:00:12.340 --> 00:00:15.210`)
and cue identifiers — just read the speaker dialogue. The content is what matters,
not the timing.

For `.txt` files, process as-is.

---

## Episode Processing

When a transcript is provided, produce all of the following assets in order.
Use clear headers for each section so Chris can copy them independently.

### 1. Episode Name

State the episode name at the top. It's the guest's full name, as it appears
in the transcript.

### 2. Guest Research & Introduction

Do a quick web search on the guest to find:
- Current role and employer
- Notable projects, contributions, or community involvement
- Fun facts, side projects, or human details that make them interesting

Write a 2–3 sentence introduction suitable for an episode page. Sound like a
podcast host, not a press release — warm, personal, a little playful. Focus on
who they are as a person and community member. Note where you found the
information.

### 3. Episode Summary

A complete bullet-pointed breakdown of the conversation. Cover:
- Main topics and themes
- Specific products, tools, projects, communities, or companies mentioned
- Interesting personal stories, opinions, or tangents
- Memorable moments or takeaways

Keep bullets concise but complete. Someone reading this should know exactly
what the episode covers without having listened.

**Suggested Links:** After the summary, list everything from the conversation
worth linking in show notes — guest's website, social profiles, projects,
companies, etc. Format as `[Name](URL)` where you can find the URL, or just
the name if not.

### 4. Intro Script

The intro is recorded *after* the episode, so it's grounded in what actually
happened. Write a suggested script that:
- Teases the guest and topics rather than spelling them out
- Sounds natural spoken aloud — conversational rhythm, not scripted-stiff
- Runs about 1–2 minutes when read at a relaxed pace (~200–300 words)
- Ends with a warm invitation to listen

Label it clearly as a suggested script Chris can adapt.

### 5. Show Notes SEO Meta Description

For the episode page (which has the audio/video embeds and links). Should:
- Summarize the conversation in 1–2 natural sentences
- Be 150–160 characters (count carefully — this is a hard limit)
- Be unique from the blog post meta description
- Not be keyword-stuffed

### 6. Blog Post

Blog posts go up 2 days after the episode. They live on the blog and link back
to the episode page.

**Title suggestions:** Give 3–5 options. Real examples from the show to
calibrate the style:
- *Carl Alexander on scaling and being true to yourself*
- *John Hawkins and "return on connection"* (note: sometimes a quote from the
  episode works brilliantly as a title subject)
- *Mike Demo on Open Source, Community and Vegan Food* (personal details like
  dietary choices, hobbies, quirks can absolutely be subjects if they came up
  meaningfully in the conversation)
- *Tearyne Almendariz on identity and intentional community spaces*
- *Dee Teal on cultural algorithms, belonging and bias*

The subjects should focus on human and personal angles wherever possible.
Technical subjects are fine if the conversation demands it (note your reasoning).
Titles should have subjects Chris can easily swap out.

**Blog post body:** Approximately 180–200 words. An executive summary of the
episode. Humanistic voice. Can include a funny or quirky moment from the
conversation — something that shows personality — but don't dwell on technical
depth. End with a clear CTA: listen to the episode or subscribe. Link the CTA
to communitycode.dev.

**Blog post SEO meta description:** 150–160 characters. Unique from the episode
meta description. Include natural keywords from the post. Optimized for
discovery.

### 7. Episode Tags

5–10 tags. Draw from:
- Guest's name
- Topics discussed
- Ecosystem (e.g., WordPress, Drupal, open source, DevRel)
- Human themes (e.g., burnout, mental health, community building, career change,
  DEIB, speaking, events, remote work)

Format as a comma-separated list.

### 8. Bluesky Post

Goes out when the blog post publishes (2 days after episode). Should:
- Sound like a real person posted it, not a brand
- Tease with personality — can be funny, warm, or intriguing
- Include a placeholder for the blog URL: `[BLOG POST URL]`
- Stay under 300 characters
- Use 1–2 hashtags at most (or none — don't force it)

### 9. LinkedIn Video Promo Posts

Each episode has short video clips that go out on a regular social cadence. The clips live in an `assets/` subfolder inside the episode folder. Their filenames describe what each clip covers — use those titles plus the transcript to ground the copy in something specific.

**Standard release cadence and post purpose:**

| Day | Post purpose | Tone |
|-----|-------------|------|
| Monday (pre-release) | "it's coming" | Tease — build anticipation |
| Wednesday (release day) | "it's here" | Episode is live — invite people in |
| Friday (post-release) | "did you miss it?" | One more push, with blog post |
| Monday (following) | Newsletter context | Usually automated — no video post needed |

If the episode releases on a non-standard day (e.g., Friday for a special event), the cadence shifts accordingly — there may be two pre-release tease posts before the drop, with the "did you miss it?" post moving to the following Monday. Write copy that fits the actual schedule, not an assumed one.

**How to write LinkedIn video copy:**

Read the clip title and relevant transcript sections to find the sharpest angle — a quote, a counterintuitive statement, a specific story, or a memorable moment. Don't describe the video generically. Each post should feel like Chris is telling a friend about one specific thing that happened in the conversation.

Style rules:
- First-person voice, from Chris
- Open with a hook: a direct quote, a surprising claim, or a specific detail — never a generic "this week on Community + Code..."
- 2–3 short paragraphs max
- Specific beats from the episode — not "we talked about X" but "here's the actual thing that was said/happened"
- Warm, casual, developer-friendly — never corporate or hype-y
- End with a simple one-line CTA and a `[EPISODE URL]` placeholder
- No hashtags required (use sparingly only if they add something)

**Calibration examples** (real posts from the show — use these to match the voice and style):

*Monday tease:*
> "You are legitimizing the theft of copyrighted code."
>
> That's Juliette Reinders Folmer — maintainer of PHP CodeSniffer, one of the most widely used PHP tools in existence — on what happens when developers submit AI-generated pull requests to open source projects. It's not just an ethics argument. It's a licensing time bomb. And she explains it better than anyone I've heard.
>
> Catch the full episode of Community + Code this week. Subscribe so you don't miss it: [EPISODE URL]

*Wednesday / release day:*
> There *are* servers in serverless architecture.
>
> In today's episode of Community + Code, Carl Alexander suggests that the point of serverless has nothing to do with servers and everything to do with how much do you want to *care* about servers. In 2026, the answer is probably not at all.
>
> Check out the episode here: [EPISODE URL]

*Friday / "did you miss it?":*
> My guest this week on Community + Code had been sitting on a product idea for years. What finally pushed Fons Vandamme to ship it? LLMs — but not for the reason you'd expect.
>
> Fons figured out that the most useful thing about working with AI tools isn't that they write your code. It's that they'll be ruthlessly honest about your ideas when you ask them to be. He told me he knows he's prompting them right when an LLM doesn't say "great idea."
>
> Check out this week's episode to hear more: [EPISODE URL]

**Output format:** Write one LinkedIn post per video clip found in `assets/`. Label each with the clip filename and which posting slot it maps to (e.g., *Monday tease*, *Release day*, *Did you miss it?*).

---

## Quality Checklist

Before delivering, check:
- [ ] Episode name matches guest's name from transcript
- [ ] Guest intro is warm and human — not a LinkedIn bio
- [ ] Summary is complete and covers all notable topics
- [ ] Intro script sounds natural read aloud, runs 1–2 minutes
- [ ] Both meta descriptions are under 160 characters and unique from each other
- [ ] Blog post titles have swappable, human-interest subjects
- [ ] Blog post body is ~180–200 words and ends with a CTA
- [ ] Bluesky post is under 300 characters and sounds human
- [ ] LinkedIn posts open with a hook, are grounded in specific clip content, and don't sound like brand copy

---

## Thumbnail Extraction

When Chris asks for thumbnail candidates for an episode, extract 10–20 still
frames from the guest's webcam recording and save them to the episode folder
for review.

### Finding the right video file

Two video files may exist in the episode folder — do not confuse them:

| File | Use |
|------|-----|
| `[Guest Name].mp4` | Full episode recording — **ignore this** |
| `*-webcam-*-StreamYard*` | Guest-only webcam recording — **use this** |

The webcam file follows the pattern:
`[Guest Name]-webcam-00h_00m_00s_273ms-StreamYard` (with `.mp4` or similar
extension). Find it with:
```bash
ls ~/Documents/Community\ +\ Code/"<Guest Name>"/*-webcam-*
```

### Extraction process

1. **Get duration** using ffprobe so you know how much video to sample:
   ```bash
   ffprobe -v quiet -show_entries format=duration -of csv=p=0 "[webcam file]"
   ```

2. **Extract one frame every 20 seconds** across the full recording. This
   typically yields 100–200 candidate frames for a 1-hour session — enough
   coverage without being excessive:
   ```bash
   mkdir -p /tmp/cc_thumbs
   ffmpeg -i "[webcam file]" -vf "fps=1/20" /tmp/cc_thumbs/frame_%04d.png
   ```
   Use `/tmp/cc_thumbs/` as the working directory for raw candidates.

3. **Evaluate frames with vision.** For each frame, look for:
   - ✅ Natural, relaxed smile (not mid-laugh, not neutral)
   - ✅ Eyes clearly open and looking toward camera
   - ✅ Face well-framed and not cut off
   - ✅ Good lighting — face visible, no harsh shadows, not blown out
   - ❌ Reject: mid-blink, mouth open while speaking, eyes closed, looking
     away, unflattering transitional expression, motion blur

4. **Select 10–20 of the best candidates.** Aim for variety across the
   timeline — don't pick 15 frames from the same 5-minute stretch. Spread
   selections across early, middle, and late portions of the recording.

5. **Export final candidates** as numbered PNGs to the episode folder:
   ```bash
   cp /tmp/cc_thumbs/frame_XXXX.png \
     ~/Documents/Community\ +\ Code/"<Guest Name>"/thumbnail-01.png
   ```
   Name them `thumbnail-01.png` through `thumbnail-NN.png` in order of how
   strongly they meet the criteria (best candidates first).

6. **Report back** with a brief note on each exported frame — the approximate
   timestamp it came from and why it was selected. This helps Chris find
   nearby alternatives if he wants a slightly different moment.

7. **Clean up** the working frames from `/tmp/cc_thumbs/` when done.

### Notes

- The webcam recording is a fixed-angle shot, so framing is consistent
  throughout — focus evaluation effort on expression quality, not composition.
- If the video is very long (2+ hours), consider sampling every 30 seconds
  instead to keep the candidate set manageable.
- Do not run thumbnail extraction automatically during episode processing —
  only when Chris specifically asks for it.

---

## Local Paths Reference

| What | Path |
|------|------|
| Show assets & episode folders | `~/Documents/Community + Code/` |
| Episode folder (per guest) | `~/Documents/Community + Code/[Guest Name]/` |
| WordPress site repo | `~/pantheon-local-copies/communitycodedev` |

**Show assets folder** contains general production files (PSD files, logo
files, etc.) alongside individual episode folders. Large files are backed up
to external storage after episode prep is complete.

**Episode folders** are named by guest name only — no season prefix or date.
Transcripts, when not uploaded directly, may be found here.

**WordPress repo** is a locally cloned Pantheon site. When Chris asks
technical questions about communitycode.dev without a transcript, read the
source code here rather than making assumptions. Start with the theme and any
custom plugins before looking at config.

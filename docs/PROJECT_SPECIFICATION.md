# VirtualTourEasy-Style AI Virtual Tour Platform

## Complete technical architecture, product specification and implementation blueprint

> **This is the project's master implementation document.** Read it before building any feature.
> Reference product: https://virtualtoureasy.com/

The objective is to build a fully functional Laravel application that reproduces the core capabilities of VirtualTourEasy, including interactive 360° virtual tours, AI panorama generation, AI property videos, creative video styles, music and sound selection, publishing, embedding and analytics.

This is not a request to build a landing page or a basic 360° image viewer. It is a complete application with a property management system, media library, visual tour editor, AI generation studio, video editor and public-facing tour experience.

---

## 1. Final technology decisions

A few important decisions are made up front to avoid unnecessary services, duplicated functionality and expensive infrastructure.

### Approved technology stack

| Layer                        | Technology                                                     |
| ---------------------------- | -------------------------------------------------------------- |
| Backend                      | Laravel 13 / PHP 8.4                                           |
| Frontend                     | Vue 3 + Inertia.js + TypeScript                                |
| Styling                      | Tailwind CSS                                                   |
| UI components                | shadcn-vue                                                     |
| Database                     | MySQL                                                          |
| Queue                        | Redis + Laravel Horizon                                        |
| Media storage and CDN        | Cloudinary                                                     |
| Interactive 360° viewer      | Photo Sphere Viewer                                            |
| Panorama rendering           | WebGL / Three.js through viewer                                |
| Text-to-360                  | Blockade Labs Skybox                                           |
| Photo-to-360                 | Skybox Image-to-Panorama, validated before launch              |
| AI reasoning and planning    | OpenAI API                                                     |
| AI video generation          | Google Veo 3.1 Fast                                            |
| Alternative video generation | fal.ai, optional provider                                      |
| AI music                     | Google Lyria                                                   |
| Video assembly               | FFmpeg                                                         |
| Public tour hosting          | Laravel application and Cloudinary CDN                         |
| Payments                     | Existing payment infrastructure, Paystack/Stripe when required |

### Important provider decisions

- **OpenAI** is the intelligence and planning layer. Use it for image understanding, property analysis, scene classification, video shot planning, creative prompts and structured output.
- **Skybox** is the specialist 360° panorama-generation provider.
- **Google Veo** is the initial video-generation provider. Keep the architecture open to fal.ai models, but don't integrate every model immediately.
- **Google Lyria** generates original background music.
- **Veo-generated audio** can supply environmental sounds when supported by the selected generation configuration.
- **Cloudinary** stores and delivers media. **FFmpeg** handles final video composition.
- **Photo Sphere Viewer** handles interactive panoramas in the browser.

Do not introduce ElevenLabs initially. Voiceover should be an optional later feature, with user-uploaded narration supported from the beginning.

---

## 2. Product scope: everything the application must do

The reference product supports four input methods:

1. Describe a space using text.
2. Upload ordinary property photographs.
3. Upload existing 360° panoramas.
4. Paste a property listing or website URL.

The application must support all four. The tour and video should be created from the same source media rather than treated as unrelated products.

### A. The five main application modules

1. **Property and Media Library** — Manage properties, projects, original photos, panoramas, generated assets, videos, logos and audio files.
2. **AI Studio** — Generate 360° scenes, convert photos into panoramas, analyse images, enhance images and automatically construct tours.
3. **Virtual Tour Builder** — Drag-and-drop scene management, 360° previews, hotspots, information panels, audio, maps, branding and publishing.
4. **AI Video Studio** — Shot planning, video styles, AI camera movement, soundtracks, ambient sound, uploaded narration, clip regeneration and MP4 export.
5. **Publishing and Analytics** — Public tour links, embedded tours, branded pages, video pages, visitor tracking, lead capture and team collaboration.

### B. User workflows

**Workflow 1: Generate a complete tour from a description**

1. Create a property.
2. Select Create with AI.
3. Enter the property description.
4. Choose property type, style, number of scenes and preferred atmosphere.
5. OpenAI creates a structured scene plan.
6. Skybox generates the individual panoramas.
7. Laravel creates the scene records.
8. AI suggests scene order and navigation connections.
9. The user reviews the result in the visual builder.
10. Publish the tour.

**Workflow 2: Generate a tour from ordinary photos**

1. Upload multiple property photographs.
2. Analyse the images using OpenAI vision.
3. Identify rooms, property features and likely room relationships.
4. Let the user correct the classifications.
5. Submit the selected images to the photo-to-panorama provider.
6. Validate the generated panoramas.
7. Create scenes and navigation suggestions.
8. Allow editing before publishing.

**Workflow 3: Import existing 360° photographs**

1. Upload one or more equirectangular panoramas.
2. Validate image dimensions and aspect ratio.
3. Create a scene for each image.
4. Generate thumbnails.
5. Let the user arrange scenes.
6. Add hotspots.
7. Publish.

**Workflow 4: Generate a tour from a URL**

1. User pastes a property listing URL.
2. Backend safely retrieves the permitted public page.
3. Extract title, property description and accessible images.
4. Present extracted information for review.
5. Analyse images.
6. Generate panoramas where appropriate.
7. Assemble the tour.

Do not bypass authentication, CAPTCHAs, paywalls or anti-bot restrictions. Implement SSRF protection, domain validation, file validation and image attribution/usage checks. A URL import must not silently assume that publicly viewable images are licensed for reuse.

**Workflow 5: Generate an AI video from a tour**

1. Select an existing property or tour.
2. Select photos/scenes.
3. Choose a video style.
4. Choose aspect ratio.
5. Choose duration.
6. Choose music and sound.
7. Generate the AI shot list.
8. Review and edit the storyboard.
9. Generate individual video clips.
10. Assemble clips using FFmpeg.
11. Preview and regenerate individual clips.
12. Export, publish and share.

The video studio must work independently of the tour builder too. Users should be able to generate a video directly from property photos without first publishing a tour.

---

# 3. Complete implementation specification

The following is the actual build specification. It includes the architecture, modules, database, API integrations, user interfaces, generation workflows, job processing, security, testing and deployment requirements.

## PROJECT OBJECTIVE

Build a complete, production-ready AI Virtual Tour Platform inspired by the functionality of VirtualTourEasy.

Reference websites:

- https://virtualtoureasy.com/
- https://virtualtoureasy.com/virtual-tour-software
- https://virtualtoureasy.com/ai-virtual-tour-video

The application must allow users to create professional interactive 360° virtual tours and cinematic property videos from ordinary property photographs, text descriptions, existing 360° panoramas and supported property listing URLs.

This is a complete application, not a landing-page project or a prototype.

The objective is functional feature parity with the main advertised workflows of VirtualTourEasy, using our own implementation, interface, database and integrations.

Do not copy its source code, proprietary assets, branding or exact UI design. Build an original, professional product with equivalent functionality.

---

# PART 1 — TECHNOLOGY STACK

### Backend

- Laravel 13.
- PHP 8.4.
- MySQL.
- Laravel Sanctum where API authentication is required.
- Laravel Policies and Gates for authorization.
- Laravel Form Requests for validation.
- Laravel Notifications for user notifications.
- Redis for queues and cache.
- Laravel Horizon for queue management.

### Frontend

- Vue 3.
- Inertia.js.
- TypeScript.
- Tailwind CSS.
- shadcn-vue.
- Lucide icons.
- VueUse where useful.
- A suitable Vue drag-and-drop library for scene ordering.

### Media and rendering

- Cloudinary for image/video/audio storage, CDN delivery, thumbnails and media transformations.
- FFmpeg for local video assembly, transitions, audio mixing, encoding and export.
- Photo Sphere Viewer for interactive 360° panorama rendering.
- Photo Sphere Viewer Virtual Tour plugin.
- Photo Sphere Viewer Markers plugin.

Install the required packages:

```bash
npm install @photo-sphere-viewer/core
npm install @photo-sphere-viewer/virtual-tour-plugin
npm install @photo-sphere-viewer/markers-plugin
```

Official documentation: https://photo-sphere-viewer.js.org/

### AI providers

**OpenAI API**

- Property photo analysis.
- Image classification.
- Room identification.
- Property descriptions.
- Scene planning.
- Video shot planning.
- Prompt generation.
- Creative direction.
- Structured JSON outputs.
- Scene ordering suggestions.

Use an appropriate current vision-capable model for image analysis and a cost-efficient text model for routine planning.

Documentation: https://platform.openai.com/docs/

**Blockade Labs Skybox AI**

- Text-to-360 panorama generation.
- Photo-to-panorama generation.
- Panorama editing where supported.

API: https://docs.blockadelabs.com/api/

**Google Veo 3.1 Fast**

- Image-to-video generation.
- Cinematic camera movements.
- Property video clips.
- Ambient sound when supported by the selected model configuration.

Documentation: https://ai.google.dev/gemini-api/docs/video

**Google Lyria 3.5**

- Original AI soundtrack generation.
- Music based on selected moods and creative directions.

Documentation: https://ai.google.dev/gemini-api/docs/music-generation

**fal.ai**

- Optional secondary video-generation provider.
- Keep the architecture compatible with additional video models.
- Do not make this a required dependency for the first release.

Documentation: https://fal.ai/

### Important provider rule

Never make the application depend directly on a particular AI provider throughout the business logic.

Implement provider interfaces and adapters.

We must be able to replace Skybox, Veo, OpenAI or another provider without rewriting the tour builder, video studio, database or user-facing workflows.

Do not expose API keys in Vue, JavaScript, HTML or browser network requests.

All third-party API requests must originate from the Laravel backend or secure background workers.

---

# PART 2 — APPLICATION STRUCTURE

Main navigation:

1. Dashboard
2. Properties
3. Media Library
4. AI Studio
5. Virtual Tours
6. Video Studio
7. Analytics
8. Team
9. Settings

The dashboard should show:

- Total properties.
- Total virtual tours.
- Published tours.
- Total scenes.
- Generated panoramas.
- Generated videos.
- Total tour views.
- Recent activity.
- Recent properties.
- Recent generations.
- Quick actions.

Quick actions:

- Create Property.
- Generate 360° Tour.
- Upload 360° Images.
- Create Virtual Tour.
- Generate AI Video.
- Import Property URL.

The application must support desktop, tablet and mobile layouts.

Use a clean, professional SaaS interface. Prefer a light theme, neutral surfaces, good typography, subtle borders, restrained shadows and clear spacing.

Avoid unnecessary gradients, excessive rounded cards, oversized headings and decorative elements.

The application should feel like a professional property technology platform.

---

# PART 3 — PROPERTY MANAGEMENT

A property is the central container for all related media, panoramas, tours and videos.

### Property fields

- Name.
- Slug.
- Property type.
- Description.
- Address.
- City.
- State/region.
- Country.
- Latitude.
- Longitude.
- Bedrooms.
- Bathrooms.
- Area.
- Area unit.
- Price (optional).
- Currency.
- Cover image.
- Status.
- Owner.
- Team.
- Additional metadata.

### Property types

- Residential.
- Apartment.
- Luxury property.
- Villa.
- Hotel.
- Resort.
- Commercial building.
- Office.
- Restaurant.
- Retail space.
- School.
- University.
- Museum.
- Event venue.
- Construction project.
- Architectural project.
- Tourism destination.
- Other.

Users must be able to:

- Create properties.
- Edit properties.
- Delete properties.
- Duplicate properties.
- Search and filter properties.
- View associated media.
- View associated virtual tours.
- View generated videos.
- View analytics.

Every media asset, tour, generation and video project must be associated with its relevant property where applicable.

---

# PART 4 — MEDIA LIBRARY

Build a central media library using Cloudinary.

### Supported uploads

Images: JPG, JPEG, PNG, WEBP, HEIC (where conversion is supported).

360° panoramas: JPG, JPEG, PNG, WEBP.

Audio: MP3, WAV, M4A.

Video: MP4, MOV, WEBM (where supported).

### Media asset information

Store:

- Original filename.
- Asset type.
- MIME type.
- File size.
- Width.
- Height.
- Duration where applicable.
- Cloudinary public ID.
- Secure URL.
- Thumbnail URL.
- Resource type.
- Format.
- Associated property.
- Associated tour.
- Source type.
- Generated or uploaded.
- Provider.
- Generation ID.
- Uploading user.
- Created date.
- Metadata.

### Cloudinary implementation

Create a dedicated Cloudinary service.

Implement:

- Secure uploads.
- Signed uploads where appropriate.
- Server-side upload verification.
- Asset deletion.
- Thumbnail generation.
- Optimized delivery.
- Video delivery.
- Audio delivery.
- Asset metadata retrieval.

Rules:

- Preserve original uploaded images.
- Never overwrite original images with transformed versions.
- Store generated and transformed assets as separate records.
- For equirectangular panoramas, preserve the full image and its 2:1 geometry. Never apply automatic center cropping to the original panorama.
- Use Cloudinary's supported transformations for ordinary image previews and video delivery.
- For large uploads, use appropriate upload chunking or direct signed uploads rather than routing every large file through PHP memory.
- Keep the Cloudinary API secret exclusively on the backend.

---

# PART 5 — AI STUDIO

Create a dedicated AI Studio with four creation modes.

## MODE A: TEXT-TO-360 TOUR

Allow users to describe an entire property or individual rooms.

Example:

> "A modern luxury four-bedroom apartment in Lagos, contemporary African interior design, large windows, marble flooring, warm natural lighting and premium furnishings."

### User inputs

- Property type.
- Number of scenes.
- Interior style.
- Architectural style.
- Lighting.
- Time of day.
- Color palette.
- Atmosphere.
- Additional instructions.
- Negative prompt.
- Output quality where supported.

Styles: Modern, Minimalist, Scandinavian, Luxury, Contemporary, Industrial, Traditional, Mediterranean, Tropical, Classic, Custom.

### Generation workflow

1. Submit the property description to OpenAI.
2. Generate a structured property plan.
3. Generate a list of scenes.
4. For each scene, generate:
    - Scene title.
    - Room type.
    - Scene description.
    - Panorama prompt.
    - Negative prompt.
    - Suggested order.
    - Suggested connections.
5. Display the generated scene plan for user review.
6. Submit panorama requests to Skybox.
7. Track each generation independently.
8. Download completed panoramas.
9. Validate the outputs.
10. Upload the generated assets to Cloudinary.
11. Create media records.
12. Create tour scenes.
13. Suggest navigation hotspots.
14. Open the completed tour in the editor.

Rules:

- Do not automatically generate all panoramas before the user confirms the scene plan.
- Allow users to edit individual scene prompts before generation.
- Support regenerating one scene without regenerating the whole property.

## MODE B: PHOTO-TO-360 PANORAMA

Allow a user to upload a regular photograph and convert it into a 360° panorama.

### Workflow

1. Upload the source photograph.
2. Store the original in Cloudinary.
3. Send the image to the supported Skybox Image2Pano endpoint.
4. Use an eligible Model 4 style.
5. Poll or receive completion through a webhook.
6. Retrieve the completed panorama.
7. Validate the output.
8. Upload it to Cloudinary.
9. Create a new panorama asset.
10. Allow the user to preview and add it to a tour.

Rules:

- Use the official Skybox Image2Pano API implementation.
- Do not assume every Skybox model supports image-to-panorama conversion.
- Do not use the normal text-to-skybox endpoint as a substitute for Image2Pano.

**Important:** AI outpainting invents parts of a room outside the source photograph. It does not reconstruct a physically accurate 360° capture. Show an appropriate notice when users generate synthetic panoramas.

## MODE C: UPLOAD EXISTING 360° IMAGES

Allow users to upload panoramas from Insta360, Ricoh Theta or other 360° cameras.

### Validation

- Validate MIME type.
- Validate image dimensions.
- Check that width-to-height ratio is approximately 2:1.
- Detect malformed or corrupted images.
- Generate thumbnails.
- Preserve original resolution.
- Warn when an uploaded image is not a valid equirectangular panorama.

Do not reject every non-perfect 2:1 image automatically. Allow users to inspect and correct supported images where practical.

Create a scene for each valid panorama. Allow multiple uploads and bulk scene creation.

## MODE D: PROPERTY URL IMPORT

Allow users to paste a public property listing or website URL.

### Workflow

1. Validate the URL.
2. Validate the destination domain and resolved IP.
3. Reject private/internal IP ranges and unsafe redirect destinations.
4. Retrieve accessible public page content.
5. Extract relevant property details and image references.
6. Display extracted information for confirmation.
7. Let the user select the images to import.
8. Analyse selected images with OpenAI.
9. Continue into the photo-to-panorama or video workflow.

Rules:

- Implement SSRF protection, request timeouts, content-type checks, maximum download sizes and safe redirect handling.
- Do not bypass authentication, paywalls, CAPTCHA or website access controls.
- Respect robots policies, applicable website terms and image usage rights.
- If a site cannot be imported, allow manual uploads.

---

# PART 6 — OPENAI INTELLIGENCE LAYER

Create an OpenAI service responsible for structured reasoning and planning.

Use vision-capable models for image analysis. Do not use image generation as a substitute for image analysis.

### Property photo analysis

Given a collection of images, identify:

- Likely room type.
- Indoor/outdoor classification.
- Visible architectural features.
- Furniture.
- Lighting.
- Major visual characteristics.
- Property features.
- Image quality.
- Duplicate or near-duplicate images.
- Suggested scene title.
- Suggested tour order.

Return structured JSON. Example:

```json
{
    "property_type": "apartment",
    "scenes": [
        {
            "title": "Living Room",
            "room_type": "living_room",
            "description": "Bright contemporary living room",
            "source_image_ids": ["asset-1"],
            "suggested_order": 1,
            "suggested_connections": ["kitchen", "balcony"]
        }
    ]
}
```

The model must not invent room details that are not visible in the source images. Clearly distinguish detected facts from inferred suggestions.

### Tour planning

Generate:

- Tour title.
- Tour description.
- Scene order.
- Navigation suggestions.
- Scene descriptions.
- Hotspot titles.
- Information panel text.
- Introductory text.

### Video planning

Generate:

- Video title.
- Creative direction.
- Opening shot.
- Shot sequence.
- Selected source image per shot.
- Camera movement.
- Duration.
- Transition recommendation.
- Visual prompt.
- Negative prompt.
- Closing shot.

Return structured JSON that is validated against application schemas.

Do not allow unvalidated model output to directly control provider requests, database queries or file operations.

### AI prompt generation

Prompts must be tailored to the selected style, scene and source image.

For property videos, prioritize:

- Architectural preservation.
- Furniture consistency.
- Realistic room proportions.
- Natural camera movement.
- Stable windows, walls and doors.
- No unexpected objects.
- No scene morphing.
- No artificial structural changes unless explicitly requested.

The AI planning service should be reusable by both the panorama and video workflows.

---

# PART 7 — VIRTUAL TOUR BUILDER

This is one of the most important modules.

Build a browser-based visual tour editor.

- Use Photo Sphere Viewer as the actual panorama renderer.
- Use its Virtual Tour plugin for scene navigation.
- Use its Markers plugin for navigation and information hotspots.

Official documentation:

- https://photo-sphere-viewer.js.org/plugins/virtual-tour.html
- https://photo-sphere-viewer.js.org/plugins/markers.html

## Editor interface

Create a three-part editor layout.

### Left sidebar

- Scene list.
- Scene thumbnails.
- Scene title.
- Scene order.
- Add scene.
- Upload panorama.
- Generate scene.
- Duplicate scene.
- Delete scene.
- Drag-and-drop ordering.

### Main workspace

- Large interactive 360° panorama.
- Drag to look around.
- Zoom.
- Fullscreen.
- Preview mode.
- Hotspot placement.
- Initial view controls.

### Right sidebar

Context-sensitive settings:

- Scene settings.
- Hotspot settings.
- Information panel settings.
- Branding.
- Audio.
- Map.
- Scene details.

### Top toolbar

- Back.
- Tour title.
- Save status.
- Preview.
- Publish.

## Scene management

Each scene must support:

- Title.
- Description.
- Panorama asset.
- Thumbnail.
- Initial yaw.
- Initial pitch.
- Initial zoom/FOV.
- Scene order.
- Audio configuration.
- Visibility.
- Status.

Allow the user to set the initial viewing direction of each scene. Save the initial orientation so every visitor sees the intended starting view.

## HOTSPOTS

### 1. Navigation hotspot

Connects one scene to another (e.g. Living Room → Kitchen).

Fields: source scene, target scene, yaw, pitch, icon, label, tooltip, animation settings, visibility.

### 2. Information hotspot

Displays an information panel (e.g. "Premium marble flooring with floor-to-ceiling windows.").

Fields: title, description, image, optional video, yaw, pitch, panel style.

### 3. External link hotspot

Opens a website or booking link.

Fields: title, URL, description, yaw, pitch, open-in-new-tab setting.

### 4. Media hotspot

Displays an image or video overlay.

Fields: media asset, title, description, position, display settings.

### Hotspot placement

When the user selects Add Hotspot:

1. Enter placement mode.
2. Allow the user to click a position in the panorama.
3. Capture the spherical coordinates.
4. Open the relevant configuration panel.
5. Save the hotspot.
6. Render it in the viewer.

Rules:

- Store yaw and pitch in a consistent, documented unit.
- Do not mix radians and degrees across backend and frontend.
- Use the same coordinate conversion for creation, editing and rendering.
- Allow users to reposition hotspots by dragging where supported.

## SCENE NAVIGATION

Implement:

- Smooth scene transitions.
- Previous/next scene controls where configured.
- Scene thumbnails.
- Scene navigation hotspots.
- Optional scene gallery.
- Optional map/plan navigation.

The tour viewer should not require users to understand the editor.

## TOUR AUDIO

Allow users to configure:

- Background music for the entire tour.
- Ambient audio.
- Per-scene audio.
- Uploaded audio files.
- Audio volume.
- Mute/unmute.
- Loop.
- Autoplay preference where browser policies permit.

Browsers often prevent automatic audio playback. Provide a clear user-controlled sound activation button. Do not start audible playback without an appropriate user interaction.

## MAP AND FLOOR PLAN

Map:

- Property location.
- Map marker.
- Scene location markers when coordinates exist.
- Navigation from a map marker to a scene.

Floor plan:

- Upload floor-plan image.
- Place scene markers on the floor plan.
- Connect markers to the appropriate panorama.
- Highlight the current scene.

These features should be modular and should not block the first tour release.

---

# PART 8 — PUBLIC VIRTUAL TOUR VIEWER

Every published tour must have a public URL, e.g. `/t/{tour-slug}`.

Use secure, non-guessable public identifiers where appropriate.

### Viewer features

- Full-screen 360° panorama.
- Drag to look around.
- Mouse and touch navigation.
- Zoom.
- Scene-to-scene navigation.
- Hotspots.
- Information panels.
- External links.
- Scene thumbnails.
- Optional map.
- Optional floor plan.
- Background music.
- Mute/unmute.
- Property information.
- Logo and branding.
- Contact/lead form.
- Responsive mobile layout.

### Branding

Allow owners to configure:

- Logo.
- Primary brand colour.
- Accent colour.
- Intro screen.
- Tour title.
- Tour description.
- Watermark visibility.
- Contact information.

### Performance

- Keep the public viewer lightweight.
- Load only the assets required for the current scene and preload the next scene when practical.
- Use optimized panorama delivery and consider multiresolution/tiled delivery for very large panoramas.
- Do not load every full-resolution panorama into browser memory at once.

### Embed support

Generate iframe embed code:

```html
<iframe
    src="https://yourdomain.com/t/property-slug"
    width="100%"
    height="600"
    frameborder="0"
    allowfullscreen
    loading="lazy"
>
</iframe>
```

Support:

- Responsive embeds.
- Fullscreen.
- Custom embed height.
- Optional branding settings.
- Allowed embedding domains where configured.

---

# PART 9 — AI VIDEO STUDIO

The video studio must accept:

1. Property photographs.
2. Existing 360° tour scenes.
3. Selected media from the media library.
4. A combination of eligible sources.

Users must be able to generate videos without first creating a virtual tour.

## VIDEO CREATION WIZARD

### STEP 1: SELECT SOURCE

Allow the user to:

- Select a property.
- Upload photographs.
- Select media assets.
- Select an existing virtual tour.
- Select individual tour scenes.
- Change the scene order.

Allow a maximum of nine source images per initial video project unless the chosen generation workflow supports a different limit. Validate images and show previews.

### STEP 2: CHOOSE VIDEO STYLE

Presets:

1. **Property Walkthrough** — Smooth, steady cinematic camera movement that moves through the rooms.
2. **Drone-Style Reveal** — Aerial-inspired establishing shots, slow rise and orbit effects. Clearly distinguish simulated camera movement from actual aerial footage.
3. **Social Media Teaser** — Fast, visually engaging promotional video for Reels, TikTok and YouTube Shorts.
4. **Before-and-After Staging** — Show an empty room followed by a furnished/staged version. Require an appropriate before/after source or generate a staged image with explicit user approval. Do not silently fabricate the original property condition.
5. **Luxury Showcase** — Slow cinematic movements, elegant transitions, premium lighting and restrained pacing.
6. **YouTube Property Advertisement** — Opening property reveal, room highlights, standout features and closing hero shot.
7. **New Development Launch** — Promotional sequence for new construction, architectural renders and developments.
8. **Commercial Property Promo** — Dynamic promotional videos for hotels, offices, restaurants, retail and other commercial spaces.
9. **Custom Style** — Users describe the desired video in natural language. The AI converts this into a validated creative plan using supported styles and controls.

### STEP 3: VIDEO FORMAT

Support 16:9 widescreen, 9:16 vertical and 1:1 square.

- Offer appropriate resolution options supported by the selected video provider.
- Use FFmpeg to produce the final output aspect ratio.
- Avoid destructive stretching or cropping.
- Use intelligent fitting and safe crop regions.

### STEP 4: VIDEO DURATION

Support configurable durations up to 30 seconds for the initial version: 5, 10, 15, 20, 30 seconds.

- The selected duration must determine the shot allocation.
- Do not assume the AI provider will return exactly the requested duration. Validate actual output durations and assemble accordingly.

### STEP 5: SOUND SELECTION

This is an important part of the product. Provide a dedicated soundtrack and audio selection interface.

#### Sound modes

- **A. No Sound** — Generate a silent video.
- **B. AI Music** — Generate original music with Google Lyria.
- **C. Ambient Sound** — Use model-generated environmental sound when supported, or select licensed sound-effect assets from the application's library.
- **D. Own Audio** — Allow users to upload MP3 or WAV files. Accept recorded voiceovers and properly licensed music.

#### Music moods

Luxury, Cinematic, Calm, Elegant, Modern, Corporate, Warm, Romantic, Upbeat, Inspirational, Minimal, Dramatic.

#### Music generation workflow

1. User selects AI Music.
2. User chooses a mood.
3. OpenAI or a deterministic prompt template prepares a suitable music description.
4. Google Lyria generates the soundtrack.
5. Store the result in Cloudinary.
6. Attach it to the video project.
7. Mix it with the final video using FFmpeg.

Lyria is responsible for music generation, not video editing.

#### Ambient sound

Options: Nature, Wind, Birds, Ocean, City ambience, Soft room ambience, Rain, Water, Fireplace, Outdoor atmosphere.

Use Veo native audio where appropriate, or licensed sound assets. Do not assume a video model generates separate, editable sound-effect tracks.

#### User-uploaded sound

Allow MP3, WAV and appropriate M4A files. Provide:

- Preview.
- Volume.
- Trim start/end.
- Fade in.
- Fade out.
- Replace audio.

#### Audio mixing

FFmpeg must handle:

- Background music.
- Ambient sound.
- User narration.
- Volume balancing.
- Audio fades.
- Audio duration.
- Audio/video synchronization.

Preserve audio tracks only when they are selected or generated intentionally. Provide a mute option.

### STEP 6: AI STORYBOARD

Before expensive video generation, use OpenAI to prepare a storyboard.

Each shot must contain:

- Shot number.
- Source image.
- Scene name.
- Duration.
- Camera movement.
- Visual description.
- Prompt.
- Negative prompt.
- Transition.
- Audio notes.

Example:

```json
{
    "video_title": "Luxury Apartment Walkthrough",
    "style": "property_walkthrough",
    "aspect_ratio": "16:9",
    "total_duration": 15,
    "shots": [
        {
            "order": 1,
            "source_image_id": "asset_001",
            "scene": "Exterior",
            "duration": 5,
            "camera_movement": "slow cinematic push-in",
            "prompt": "Preserve the exact architectural design...",
            "transition": "crossfade"
        },
        {
            "order": 2,
            "source_image_id": "asset_002",
            "scene": "Living Room",
            "duration": 5,
            "camera_movement": "gentle forward dolly",
            "prompt": "Preserve the furniture and room geometry...",
            "transition": "crossfade"
        },
        {
            "order": 3,
            "source_image_id": "asset_003",
            "scene": "Kitchen",
            "duration": 5,
            "camera_movement": "slow lateral camera movement",
            "prompt": "Maintain the original room layout...",
            "transition": "fade"
        }
    ]
}
```

The example is illustrative. Actual durations and clip counts must respect the provider's supported settings.

Allow users to:

- Edit shot order.
- Change a source image.
- Change a camera movement.
- Edit prompts.
- Remove shots.
- Regenerate the storyboard.
- Regenerate individual clips.

Do not regenerate all shots when only one shot needs changing.

### STEP 7: VIDEO GENERATION

Use Google Veo 3.1 Fast as the initial provider. Implement a dedicated provider adapter.

Support:

- Image-to-video generation.
- Configurable duration where supported.
- Aspect ratio.
- Supported resolution.
- Prompt.
- Reference image.
- Native audio options where supported.

Use the official API contract rather than hardcoding assumptions about model capabilities.

For every clip:

1. Validate the source image.
2. Build the final prompt.
3. Submit the provider request.
4. Save provider request ID.
5. Save model and configuration.
6. Track job status.
7. Poll or receive completion.
8. Retrieve the generated clip.
9. Validate the output.
10. Store it in Cloudinary.
11. Update the video project.

Support optional fal.ai adapters for alternative models.

Do not assume every model supports the same clip duration, resolution, aspect ratio, reference image count, native audio, or first/last frame controls. Expose only options supported by the selected model.

### STEP 8: VIDEO ASSEMBLY WITH FFMPEG

Install FFmpeg on the Laravel Forge server. FFmpeg must be invoked through queued jobs and a controlled rendering service. Never run a long FFmpeg render inside a normal HTTP request.

Responsibilities:

- Join generated clips.
- Normalize video dimensions.
- Normalize frame rate.
- Apply transitions.
- Add title cards.
- Add logo/outro.
- Mix audio.
- Add music.
- Add ambient effects.
- Apply fade-in/fade-out.
- Resize for aspect ratios.
- Encode final MP4.
- Generate poster thumbnails.

Rules:

- Create temporary working directories with secure permissions.
- Validate every file path and never interpolate untrusted user input directly into shell commands.
- Use Symfony Process or a similarly safe process execution method.
- Limit CPU usage and concurrent renders.
- Delete temporary files after successful delivery or after a controlled retention period.

### STEP 9: VIDEO PREVIEW AND EXPORT

The video studio must provide:

- Video preview.
- Storyboard.
- Individual clip previews.
- Regenerate clip.
- Regenerate soundtrack.
- Change music.
- Replace source image.
- Download MP4.
- Shareable video page.
- Embed code.

Support unbranded and branded exports. Allow users to disable the logo outro where their subscription permits it.

Public video page: `/v/{video-slug}`

---

# PART 10 — AI PHOTO STUDIO

Create a separate photo enhancement workflow.

Support:

- Image enhancement.
- Noise reduction.
- Upscaling.
- Detail enhancement.
- Brightness correction.
- Image resizing.
- Panorama expansion.

Rules:

- Do not assume Skybox is an upscaling provider.
- Use a suitable specialist image-enhancement provider only when required. Keep the provider replaceable.
- Preserve original images and save all edited versions separately.
- For every edit, allow the user to compare original and enhanced versions.

---

# PART 11 — DATABASE ARCHITECTURE

Design a normalized MySQL schema with appropriate foreign keys, indexes, cascading rules and JSON columns only where flexible configuration is justified.

### Suggested tables

| Table                     | Purpose                                                                                       |
| ------------------------- | --------------------------------------------------------------------------------------------- |
| `users`                   | Standard Laravel users.                                                                       |
| `workspaces`              | Organizational account or team container.                                                     |
| `workspace_members`       | User membership and roles.                                                                    |
| `properties`              | Property details and ownership.                                                               |
| `media_assets`            | Original uploads, panoramas, generated images, audio and videos.                              |
| `virtual_tours`           | Tour information, slug, branding, status and publication settings.                            |
| `tour_scenes`             | Individual panorama scenes.                                                                   |
| `tour_hotspots`           | Navigation, information, external links and media hotspots.                                   |
| `tour_scene_connections`  | Optional explicit graph relationships between scenes, if useful beyond hotspot relationships. |
| `tour_audio_tracks`       | Background and scene-specific audio configuration.                                            |
| `tour_maps`               | Tour map settings.                                                                            |
| `tour_floorplans`         | Floor-plan assets and configuration.                                                          |
| `tour_floorplan_markers`  | Scene markers positioned on floor plans.                                                      |
| `ai_generations`          | Provider requests, model, generation type, status and costs.                                  |
| `ai_generation_outputs`   | Generated files and output metadata.                                                          |
| `video_projects`          | Video configuration, source property/tour and status.                                         |
| `video_shots`             | Individual storyboard shots.                                                                  |
| `video_clips`             | Generated video clip records.                                                                 |
| `video_audio_tracks`      | Music, ambience and user-uploaded audio associations.                                         |
| `video_exports`           | Final rendered video assets.                                                                  |
| `credit_wallets`          | User/workspace credit balances.                                                               |
| `credit_transactions`     | Credit reservations, charges, refunds and adjustments.                                        |
| `subscriptions`           | Subscription details.                                                                         |
| `tour_visits`             | Visitor sessions.                                                                             |
| `tour_events`             | Scene views, hotspot clicks, interactions and other analytics events.                         |
| `tour_leads`              | Contact requests submitted by visitors.                                                       |
| `activity_logs`           | Important user and system actions.                                                            |
| `provider_webhook_events` | Webhook event IDs and processing records to prevent duplicate handling.                       |

Use UUIDs or ULIDs for externally exposed identifiers where appropriate.

## Important field requirements

### media_assets

`id`, `workspace_id`, `property_id`, `uploaded_by`, `asset_type`, `source_type`, `original_filename`, `mime_type`, `file_size`, `width`, `height`, `duration`, `cloudinary_public_id`, `cloudinary_resource_type`, `secure_url`, `thumbnail_url`, `metadata`, `created_at`.

### virtual_tours

`id`, `workspace_id`, `property_id`, `title`, `slug`, `description`, `cover_asset_id`, `status`, `published_at`, `branding_settings`, `viewer_settings`, `map_settings`, `privacy_settings`, `created_by`.

### tour_scenes

`id`, `virtual_tour_id`, `media_asset_id`, `title`, `description`, `scene_type`, `sort_order`, `initial_yaw`, `initial_pitch`, `initial_zoom`, `is_visible`, `audio_settings`, `metadata`.

### tour_hotspots

`id`, `tour_scene_id`, `target_scene_id`, `type`, `yaw`, `pitch`, `title`, `description`, `external_url`, `media_asset_id`, `settings`, `sort_order`.

### ai_generations

`id`, `workspace_id`, `user_id`, `property_id`, `generation_type`, `provider`, `model`, `provider_request_id`, `status`, `input_payload`, `output_metadata`, `estimated_cost`, `actual_cost`, `credits_reserved`, `credits_charged`, `error_message`, `started_at`, `completed_at`.

### video_projects

`id`, `workspace_id`, `property_id`, `virtual_tour_id`, `title`, `slug`, `style`, `aspect_ratio`, `requested_duration`, `resolution`, `provider`, `model`, `storyboard`, `soundtrack_mode`, `soundtrack_asset_id`, `status`, `progress`, `final_asset_id`, `settings`.

### video_shots

`id`, `video_project_id`, `source_asset_id`, `scene_id`, `sort_order`, `duration`, `camera_movement`, `prompt`, `negative_prompt`, `transition`, `provider`, `model`, `status`, `generation_id`, `output_asset_id`, `metadata`.

### credit_transactions

`id`, `workspace_id`, `user_id`, `transaction_type`, `amount`, `balance_before`, `balance_after`, `reference_type`, `reference_id`, `description`, `metadata`.

Credit reservations must be atomic and protected against concurrent requests. Do not charge users twice when a job is retried.

---

# PART 12 — QUEUE AND GENERATION ARCHITECTURE

Use Redis and Laravel Horizon. All expensive or asynchronous work must be handled through Laravel jobs.

### Suggested jobs

```
AnalysePropertyPhotos
GeneratePropertyScenePlan
GeneratePanorama
ConvertPhotoToPanorama
ValidatePanorama
DownloadProviderOutput
UploadGeneratedAssetToCloudinary
GenerateTourDescriptions
GenerateTourNavigationSuggestions
ImportPropertyUrl
ExtractPropertyImages
GenerateVideoStoryboard
GenerateVideoClip
GenerateMusicTrack
GenerateAmbientAudio
AssembleVideo
GenerateVideoThumbnail
PublishVirtualTour
ProcessTourAnalytics
RefundFailedGeneration
```

### Queues

- `default`
- `ai-analysis`
- `panorama`
- `video-generation`
- `media`
- `video-rendering`
- `analytics`

Configure queue priorities and worker limits. Video rendering should have a low concurrency limit to protect the Forge server.

## Generation status model

`pending`, `queued`, `submitted`, `processing`, `downloading`, `uploading`, `assembling`, `completed`, `failed`, `cancelled`, `refunded`.

Track progress at the project and individual task level.

Do not claim that a generation is completed until the resulting file has been retrieved, validated and stored.

## Provider integrations

Create common provider contracts. For example:

```php
interface PanoramaGenerationProvider
{
    public function generate(array $input): array;

    public function convertImageToPanorama(array $input): array;

    public function getStatus(string $requestId): array;

    public function cancel(string $requestId): array;
}
```

Create:

- `SkyboxPanoramaProvider`
- `VideoGenerationProvider`
- `GoogleVeoProvider`
- `FalVideoProvider`
- `MusicGenerationProvider`
- `GoogleLyriaProvider`
- `ImageAnalysisProvider`
- `OpenAIImageAnalysisProvider`

Keep the interfaces appropriate to the real capabilities of each provider. Not every provider must implement every optional operation.

## Webhooks and polling

Prefer provider webhooks where officially supported.

Implement:

- Signature verification where available.
- Idempotent webhook handling.
- Duplicate event prevention.
- Safe event storage.
- Status transitions.
- Retry handling.

Use scheduled polling as a fallback where necessary. Never poll excessively.

---

# PART 13 — CREDITS AND COST MANAGEMENT

Implement a credit system before exposing AI generation to customers.

Every generation type must have an internal cost calculation, e.g.:

- Text-to-360 generation.
- Photo-to-360 conversion.
- Photo enhancement.
- Video clip generation.
- Music generation.
- AI analysis.

The system must support:

1. Estimate credits before generation.
2. Display the estimate to the user.
3. Reserve credits atomically.
4. Submit provider request.
5. Calculate actual provider cost.
6. Charge according to the approved policy.
7. Refund failed or cancelled eligible requests.
8. Record every transaction.
9. Prevent duplicate deductions.

Rules:

- Store provider costs in USD separately from customer credits.
- Never hardcode provider prices throughout controllers or Vue components.
- Create a configurable provider pricing/cost configuration.
- Allow an administrator to change model prices, internal cost multipliers and customer credit rates without rewriting the application.
- Where provider billing is per second, use actual billed duration and model configuration.
- Where billing is per request or per credit, record the actual provider unit consumed.
- Do not silently charge for failed generations.
- If a provider's billing behaviour is uncertain, record it for reconciliation rather than inventing a refund policy.

---

# PART 14 — PUBLISHING AND SHARING

Every published tour must support:

- Public URL.
- Shareable link.
- Embed code.
- Preview.
- Published/unpublished state.
- SEO title.
- SEO description.
- Social sharing image.
- Custom cover.
- Branded appearance.

Every published video must support:

- Public video page.
- MP4 download.
- Embed code.
- Social sharing.
- Branded or unbranded version.

Allow tours to be private, unlisted or public. Support access control where appropriate.

Do not expose unpublished assets through predictable public URLs without authorization.

---

# PART 15 — LEAD CAPTURE

Add an optional contact form inside the public tour.

Fields: Name, Email, Phone, Message.

Associate each lead with: property, virtual tour, workspace, visitor session, date.

Allow owners to configure:

- Form title.
- Button label.
- Email notification.
- Required fields.
- Thank-you message.

Implement server-side validation and spam protection.

---

# PART 16 — ANALYTICS

### Tour overview

- Total views.
- Unique sessions.
- Average session duration.
- Views per scene.
- Most visited scenes.
- Hotspot interactions.
- Lead submissions.
- Device type.
- Country where available.
- Referrer.
- Tour completion.

### Video analytics

- Video page views.
- Plays.
- Completed plays where measurable.
- Downloads.
- Embed views.

Rules:

- Use privacy-conscious visitor identifiers.
- Do not store unnecessary personal information.
- Support consent and retention settings where applicable.
- Do not claim exact unique visitor identification when browser restrictions prevent it.

---

# PART 17 — TEAM COLLABORATION

Support workspace membership with roles:

| Role       | Permissions                                                    |
| ---------- | -------------------------------------------------------------- |
| **Owner**  | Full access, billing, team management, delete workspace.       |
| **Admin**  | Manage properties, tours, media; manage users where permitted. |
| **Editor** | Create and edit properties, scenes, tours and videos.          |
| **Viewer** | View permitted properties, tours and analytics.                |

Use Laravel Policies for all access decisions. Never rely solely on hiding frontend buttons.

---

# PART 18 — SUBSCRIPTIONS AND ADMINISTRATION

Prepare the application for subscription plans.

Potential plan limits:

- Number of properties.
- Number of tours.
- Number of scenes.
- Media storage.
- AI credits.
- Video generation.
- Team members.
- Branding removal.
- Custom domain.
- Analytics.
- API access.

Rules:

- Do not hardcode plan restrictions inside individual controllers.
- Build a centralized entitlement and usage service.
- Allow administrator-configured plans.
- For the initial build, subscription checkout can be deferred if not already required. However, the underlying credit and entitlement architecture must be ready.

Create an admin interface for:

- Users.
- Workspaces.
- Properties.
- Tours.
- AI generations.
- Failed jobs.
- Provider usage.
- Credit transactions.
- Subscriptions.
- System configuration.

Never expose API secrets in administrative frontend responses.

---

# PART 19 — GOOGLE STREET VIEW

Treat Google Street View publishing as a separate advanced integration.

Do not simulate publication by merely generating a Google Maps link.

A proper integration requires the relevant Google Maps Platform APIs, authorization, eligible 360° imagery, location metadata and compliance with Google's publishing requirements.

Implement this after the main tour platform works. Keep scene GPS coordinates and panorama metadata available so the feature can be added later.

---

# PART 20 — LARAVEL PROJECT STRUCTURE

Use a modular and maintainable structure. Suggested layout:

```
app/
├── Contracts/
│   ├── AI/
│   ├── Panorama/
│   ├── Video/
│   ├── Music/
│   └── Media/
│
├── Enums/
│   ├── AssetType.php
│   ├── GenerationStatus.php
│   ├── GenerationType.php
│   ├── HotspotType.php
│   ├── TourStatus.php
│   └── VideoStyle.php
│
├── Models/
│   ├── Workspace.php
│   ├── WorkspaceMember.php
│   ├── Property.php
│   ├── MediaAsset.php
│   ├── VirtualTour.php
│   ├── TourScene.php
│   ├── TourHotspot.php
│   ├── TourAudioTrack.php
│   ├── TourMap.php
│   ├── TourFloorplan.php
│   ├── AIGeneration.php
│   ├── VideoProject.php
│   ├── VideoShot.php
│   ├── VideoClip.php
│   ├── VideoExport.php
│   ├── CreditWallet.php
│   ├── CreditTransaction.php
│   ├── TourVisit.php
│   ├── TourEvent.php
│   └── TourLead.php
│
├── Services/
│   ├── AI/
│   │   ├── OpenAIService.php
│   │   ├── PropertyAnalysisService.php
│   │   ├── ScenePlanningService.php
│   │   └── VideoPlanningService.php
│   │
│   ├── Panorama/
│   │   ├── PanoramaService.php
│   │   ├── SkyboxService.php
│   │   └── PanoramaValidationService.php
│   │
│   ├── Video/
│   │   ├── VideoGenerationService.php
│   │   ├── VideoAssemblyService.php
│   │   ├── VideoStoryboardService.php
│   │   └── VideoExportService.php
│   │
│   ├── Music/
│   │   └── LyriaMusicService.php
│   │
│   ├── Media/
│   │   └── CloudinaryService.php
│   │
│   ├── Tours/
│   │   ├── TourBuilderService.php
│   │   ├── TourPublishingService.php
│   │   └── TourAnalyticsService.php
│   │
│   ├── Credits/
│   │   ├── CreditService.php
│   │   └── GenerationCostService.php
│   │
│   └── Imports/
│       └── PropertyUrlImportService.php
│
├── Jobs/
│   ├── AI/
│   ├── Panorama/
│   ├── Video/
│   ├── Media/
│   └── Analytics/
│
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
│
└── Policies/
```

The structure is a guideline. Adapt it to existing project conventions and avoid unnecessary empty classes.

Frontend:

```
resources/js/
├── Components/
│   ├── Panorama/
│   ├── Tour/
│   ├── Video/
│   ├── Media/
│   └── UI/
│
├── Layouts/
│
├── Pages/
│   ├── Dashboard/
│   ├── Properties/
│   ├── Media/
│   ├── AIStudio/
│   ├── Tours/
│   ├── VideoStudio/
│   ├── Analytics/
│   ├── Team/
│   └── Settings/
│
├── Composables/
│   ├── usePanoramaViewer.ts
│   ├── useTourBuilder.ts
│   ├── useMediaUpload.ts
│   └── useGenerationStatus.ts
│
├── Types/
│
└── Utils/
```

---

# PART 21 — ENVIRONMENT CONFIGURATION

Use environment variables:

```env
APP_NAME="Virtual Tour Platform"

DB_CONNECTION=mysql

QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_CLIENT=phpredis

CLOUDINARY_CLOUD_NAME=
CLOUDINARY_API_KEY=
CLOUDINARY_API_SECRET=
CLOUDINARY_URL=

OPENAI_API_KEY=
OPENAI_MODEL=

SKYBOX_API_KEY=

GOOGLE_AI_API_KEY=
GOOGLE_VEO_MODEL=
GOOGLE_LYRIA_MODEL=

FAL_KEY=

FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe

VIDEO_TEMP_PATH=
VIDEO_MAX_CONCURRENT_RENDERS=1
```

- Add configuration files for each provider.
- Validate required configuration before enabling the corresponding feature.
- If a provider key is missing, display a clear administrator configuration error instead of allowing the application to fail unexpectedly.
- Never commit real API keys.

---

# PART 22 — SECURITY AND RELIABILITY

Implement:

- Authentication.
- Workspace isolation.
- Laravel Policies.
- CSRF protection.
- Rate limiting.
- Upload validation.
- File-size limits.
- Secure MIME validation.
- Secure public URLs.
- Signed Cloudinary uploads.
- Provider key protection.
- Webhook verification.
- SSRF protection for URL imports.
- Safe FFmpeg execution.
- Idempotent jobs.
- Duplicate-generation protection.
- Atomic credit reservations.
- Queue retries with appropriate backoff.
- Job timeouts.
- Failed-generation recovery.
- Error logging.
- Audit logging.

Rules:

- Do not allow arbitrary shell commands.
- Do not trust filenames or file extensions.
- Do not accept a provider callback as proof of a valid asset without retrieving and validating the output.
- Use database transactions for related record creation and credit operations.
- Avoid keeping external API calls inside long-running database transactions.

---

# PART 23 — TESTING REQUIREMENTS

Write automated tests as each module is developed.

### Unit tests

- Credit calculations.
- Credit reservation.
- Credit refunds.
- Scene planning validation.
- Hotspot coordinate conversion.
- Panorama validation.
- Video duration calculations.
- Aspect ratio calculations.
- Provider response normalization.
- Permission checks.

### Feature tests

- Property CRUD.
- Media upload.
- Tour creation.
- Scene management.
- Hotspot creation.
- Tour publishing.
- Public tour access.
- Video project creation.
- AI generation submission.
- Job status updates.
- Failed generation refunds.
- Lead submission.
- Workspace isolation.

### Integration tests

- Use mocked providers by default.
- Test real provider APIs only through explicitly enabled integration tests.
- Do not require paid API calls for the normal test suite.

### Browser tests

- 360° viewer initialization.
- Scene navigation.
- Hotspot placement.
- Hotspot interaction.
- Responsive viewer.
- Tour publishing.
- Video preview.
- Media upload.
- Generation progress.

---

# PART 24 — DEVELOPMENT PHASES

Do not attempt to implement everything in one unreviewed operation. Build in working milestones.

## PHASE 0 — REPOSITORY INSPECTION

Before writing code:

1. Inspect the existing repository.
2. Identify the Laravel and PHP versions.
3. Inspect existing authentication.
4. Inspect existing frontend conventions.
5. Inspect installed packages.
6. Inspect the database.
7. Inspect existing queue configuration.
8. Inspect existing storage configuration.
9. Inspect deployment assumptions.
10. Identify reusable components.

- Do not overwrite existing functionality.
- Do not create a new Laravel project inside an existing Laravel repository.
- Do not assume the repository is empty.
- If the existing architecture differs from this specification, adapt the implementation without breaking existing features.

## PHASE 1 — APPLICATION FOUNDATION

Build:

- Workspace architecture.
- Property management.
- Media library.
- Cloudinary integration.
- Database schema.
- Authentication and permissions.
- Dashboard.
- Redis queues.
- Horizon configuration.

**Acceptance criteria:** A user can create a property, upload media, see their assets and organize them by property.

## PHASE 2 — INTERACTIVE VIRTUAL TOUR

Build:

- Photo Sphere Viewer integration.
- Scene management.
- 360° image validation.
- Scene ordering.
- Initial view settings.
- Navigation hotspots.
- Information hotspots.
- External link hotspots.
- Public tour viewer.
- Publishing.
- Responsive iframe embedding.

**Acceptance criteria:** A user can upload four valid panoramas, connect them with hotspots, publish the tour and explore it through a public link. This must work without any paid AI provider.

## PHASE 3 — AI PANORAMA STUDIO

Build:

- OpenAI property analysis.
- Scene planning.
- Skybox text-to-360.
- Skybox photo-to-360.
- Generation history.
- Generation status tracking.
- Automatic media storage.
- Generated scene creation.
- Regeneration.
- Credit management.

**Acceptance criteria:** A user can generate a panorama from a text description, generate one from an ordinary photograph and add both to an existing tour.

## PHASE 4 — ADVANCED TOUR FEATURES

Build:

- Branding.
- Background audio.
- Scene audio.
- Floor plans.
- Map.
- Contact forms.
- Tour analytics.
- Scene gallery.
- Team permissions.

**Acceptance criteria:** The published tour is a complete branded, interactive experience.

## PHASE 5 — AI VIDEO STUDIO

Build:

- Video project management.
- Source selection.
- Creative presets.
- Aspect ratios.
- Duration selection.
- OpenAI storyboard generation.
- Google Veo integration.
- Individual clip regeneration.
- Lyria soundtrack generation.
- User audio uploads.
- Ambient sound options.
- FFmpeg assembly.
- Video previews.
- Public video pages.
- MP4 export.

**Acceptance criteria:** A user can select property photos, choose a video style, format, duration and sound, generate the clips, assemble the final video and download a playable MP4.

## PHASE 6 — ADVANCED FEATURES

Build:

- Property URL imports.
- AI photo enhancement.
- Google Street View integration.
- Advanced analytics.
- Custom domains.
- White-label features.
- API.
- Team collaboration improvements.
- Subscription management.
- Administrative usage controls.

---

# PART 25 — IMPORTANT IMPLEMENTATION RULES

1. Build real, working functionality. Do not create buttons that do nothing.
2. Do not use placeholder AI results and claim that actual generation works.
3. Do not fake provider responses.
4. Do not hardcode sample properties into production dashboards.
5. Do not put API keys in frontend code.
6. Do not use local server storage as the permanent home for generated media.
7. Do not execute FFmpeg in HTTP controllers.
8. Do not regenerate an entire video when only one clip needs replacement.
9. Do not deduct credits twice.
10. Do not assume every video model supports identical parameters.
11. Do not crop original 360° panoramas.
12. Do not expose private tours without authorization.
13. Do not use AI to invent property details and present them as facts.
14. Do not silently modify uploaded source images.
15. Do not sacrifice the interactive tour experience to prioritize AI generation.
16. Use reusable components, services and provider adapters.
17. Add appropriate loading, empty, error, success and retry states.
18. Use database migrations rather than manually altering production tables.
19. Add tests for important business logic.
20. Preserve existing project functionality.

---

# PART 26 — REQUIRED FIRST ACTION

Start by inspecting the existing repository and producing a concise implementation assessment.

Identify:

- Existing architecture.
- Existing dependencies.
- Existing reusable code.
- Missing packages.
- Required environment variables.
- Required database migrations.
- Deployment considerations.
- Potential technical conflicts.

Then create a detailed implementation plan broken into the phases above.

Do not start by generating every module in one operation. Begin with Phase 1 and complete it properly. After Phase 1, verify the implementation and proceed to Phase 2. Continue incrementally until all phases are implemented.

At every stage:

- Run relevant tests.
- Check Laravel conventions.
- Check database relationships.
- Check authorization.
- Check queue behavior.
- Check error handling.
- Check responsive layouts.
- Verify actual provider integration when enabled.

If a feature requires a paid provider key that is not configured, complete the integration architecture and user interface, provide a clear configuration requirement and use mocked responses only in automated tests.

Do not claim that the integration is operational until it has been tested against the real provider.

---

## FINAL DEFINITION OF DONE

The application is not complete merely because the dashboard looks finished. It must support the complete journey:

1. Create a property.
2. Upload ordinary property photographs.
3. Upload existing 360° panoramas.
4. Generate 360° scenes from text.
5. Convert ordinary photos into 360° panoramas.
6. Analyse property photos with AI.
7. Automatically suggest scene organization.
8. Build and edit interactive virtual tours.
9. Add navigation and information hotspots.
10. Add music and audio.
11. Publish tours.
12. Share public links.
13. Embed tours on external websites.
14. Generate cinematic videos from property photographs.
15. Generate videos from existing tour scenes.
16. Select video styles and creative direction.
17. Select 16:9, 9:16 or 1:1 formats.
18. Select video durations.
19. Generate original AI soundtracks.
20. Upload custom narration and music.
21. Assemble video clips using FFmpeg.
22. Regenerate individual video clips.
23. Export downloadable MP4 videos.
24. Track visitor activity and leads.
25. Manage assets, users, permissions and AI credits.

The end result should be a maintainable, production-ready Laravel application with independent AI generation services, a real interactive panorama engine, a complete video generation workflow and a polished public tour experience.

Prioritize working functionality, provider cost control, maintainability and a professional user experience over unnecessary abstractions or premature optimization.

---

# 4. API and infrastructure details before implementation

### Provider pricing reference

| Provider                    | Operation              | Published price |
| --------------------------- | ---------------------- | --------------- |
| Google Veo 3.1 Fast         | 720p video with audio  | $0.10/sec       |
| Google Veo 3.1 Fast         | 1080p video with audio | $0.12/sec       |
| Google Veo 3.1 Standard     | 720p/1080p with audio  | $0.40/sec       |
| Google Lyria 3.5            | Full song              | $0.08           |
| fal.ai MiniMax H3 Max Turbo | Video                  | $0.025/sec      |
| fal.ai Kling 3.0 Pro        | Video                  | $0.14/sec       |

- Google's pricing distinguishes video configurations and resolution. The quoted Veo Fast prices are for audio-enabled generation.
- Skybox Model 4 Image2Pano costs 3 credits for Balance quality, and Model 4 requires an eligible Standard or Business plan.
- Cloudinary has a free tier with 25 monthly credits; monitor storage, transformations and delivery bandwidth as the platform grows.

> Prices change. Store them in configuration (see Part 13) and verify against the providers' pricing pages before launch.

### Official integration links

| Provider      | Purpose         | Links                                                                                                                                                                                                                                     |
| ------------- | --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OpenAI        | AI intelligence | [API keys](https://platform.openai.com/api-keys) · [Docs](https://platform.openai.com/docs/)                                                                                                                                              |
| Blockade Labs | 360° generation | [API access](https://skybox.blockadelabs.com/api) · [Docs](https://docs.blockadelabs.com/api/) · [Pricing](https://skybox.blockadelabs.com/plans)                                                                                         |
| Google AI     | Video and music | [API key](https://aistudio.google.com/apikey) · [Veo docs](https://ai.google.dev/gemini-api/docs/video) · [Lyria docs](https://ai.google.dev/gemini-api/docs/music-generation) · [Pricing](https://ai.google.dev/gemini-api/docs/pricing) |
| Cloudinary    | Media storage   | [Register](https://cloudinary.com/users/register/free) · [Docs](https://cloudinary.com/documentation) · [Pricing](https://cloudinary.com/pricing)                                                                                         |

---

# 5. Forge server preparation

On the Ubuntu Forge server, check:

```bash
php -v
php -m | grep -E 'redis|gd|imagick|exif'
redis-cli ping
ffmpeg -version
ffprobe -version
```

Install FFmpeg if necessary:

```bash
sudo apt update
sudo apt install ffmpeg -y
```

Make sure Redis and Horizon are running correctly.

**Recommendation:** Keep the Laravel application on Forge, Cloudinary as the permanent media store, and FFmpeg as a controlled background rendering process. Do not put AI generation or heavy video rendering on the normal web request lifecycle.

For the initial deployment, use the existing Forge infrastructure, but monitor CPU, RAM, queue latency and disk usage. A separate rendering worker can be introduced when video volume justifies it.

---

# 6. What to build first

Do not start with AI generation. Start with the foundation that makes the entire product possible.

### First milestone: working virtual tour application

- [ ] Create property management
- [ ] Integrate Cloudinary uploads
- [ ] Build media library
- [ ] Install Photo Sphere Viewer
- [ ] Implement multiple panorama scenes
- [ ] Build scene navigation
- [ ] Implement hotspot placement
- [ ] Implement information panels
- [ ] Build public tour viewer
- [ ] Generate share links and iframe embeds

Once this milestone works, add Skybox, OpenAI, Veo and Lyria.

### The key architectural decision

**Build the virtual tour and video studio as two independent products sharing the same property and media infrastructure.** Someone can create a video without creating a tour, or create a tour without generating a video. Both can use the same uploaded photographs.

This is the most important product decision to preserve throughout development.

---

_The reference pages are functional requirements, but their internal implementation is not publicly known. This blueprint specifies an independent implementation of their advertised capabilities, not a guarantee of identical internal behaviour._

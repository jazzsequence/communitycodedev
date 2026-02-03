# Community + Code Theme

Custom child theme for the Community + Code podcast with dark terminal aesthetic.

## Features

- **Solarized-inspired color palette** - Dark terminal warmth with welcoming accent colors
- **Typography**: Victor Mono (headings/code) + Source Sans 3 (body)
- **Modern WordPress**: Full Site Editing (FSE) with `theme.json`
- **Sass build pipeline** for organized, maintainable styles

## Color Palette

Defined in `theme.json` and available in the block editor:

- **Terminal Blue** (`#268BD2`) - Links, CTAs, code accents
- **Warm Magenta** (`#D33682`) - Highlights, active states
- **Soft Cyan** (`#2AA198`) - Secondary accents
- **Community Orange** (`#CB4B16`) - Newsletter, special highlights
- **Deep Base** (`#002B36`) - Main background
- **Elevated Surface** (`#073642`) - Cards, elevated content
- **Muted Text** (`#839496`) - Secondary text
- **Bright Text** (`#FDF6E3`) - Primary text
- **Border** (`#586E75`) - Borders and dividers

## Development

### Setup

```bash
cd web/app/themes/communitycode-theme
npm install
```

### Build Commands

```bash
# Development (watch mode with source maps)
npm run dev

# Production build (compressed, no source maps)
npm run build

# Lint SCSS
npm run lint:scss

# Auto-fix SCSS issues
npm run lint:scss:fix
```

### File Structure

```
scss/
├── base/
│   ├── _variables.scss    # Design tokens
│   └── _typography.scss   # Typography styles
├── layout/
│   ├── _header.scss       # Header & navigation
│   └── _footer.scss       # Footer
├── components/
│   ├── _posts.scss        # Post cards & archive
│   ├── _single.scss       # Single episode page
│   ├── _buttons.scss      # Buttons & CTAs
│   ├── _search.scss       # Search functionality
│   └── _plugins.scss      # Plugin integrations
├── utilities/
│   ├── _accessibility.scss # A11y styles
│   ├── _helpers.scss      # Helper utilities
│   └── _responsive.scss   # Responsive styles
└── style.scss             # Main import file
```

## Activation

1. Navigate to **Appearance > Themes** in WordPress admin
2. Activate "Community + Code" theme
3. Colors and typography will be available in the block editor

## Parent Theme

Inherits all functionality from Twenty Twenty-Five.

## License

MIT

const CATEGORY_MB = {
  document: 20,
  image: 20,
  text: 20,
  archive: 20,
  audio: 200,
  video: 200,
};

const CATEGORY_MIMES = {
  document: [
    "application/pdf",
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.ms-excel",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  ],
  image: [
    "image/jpeg",
    "image/png",
    "image/gif",
    "image/webp",
    "image/svg+xml",
  ],
  text: ["text/plain", "text/csv", "text/markdown"],
  archive: ["application/zip"],
  audio: [
    "audio/mpeg",
    "audio/mp4",
    "audio/wav",
    "audio/ogg",
    "audio/webm",
    "audio/aac",
    "audio/x-aac",
    "audio/flac",
    "audio/x-m4a",
  ],
  video: [
    "video/mp4",
    "video/webm",
    "video/ogg",
    "video/quicktime",
    "video/x-matroska",
    "video/x-msvideo",
  ],
};

export function categoryFor(mime) {
  if (!mime) return null;
  for (const [name, list] of Object.entries(CATEGORY_MIMES)) {
    if (list.includes(mime)) return name;
  }
  return null;
}

export function maxSizeMbFor(mime) {
  const cat = categoryFor(mime);
  if (!cat) return null;
  return CATEGORY_MB[cat];
}

export function maxSizeBytesFor(mime) {
  const mb = maxSizeMbFor(mime);
  return mb == null ? null : mb * 1024 * 1024;
}

export function isAllowedMime(mime) {
  return categoryFor(mime) !== null;
}

# FFmpeg "moov atom not found" / Invalid MP4

## What the error means

When you see in logs:

- **Faststart optimization failed** or **HLS variant generation failed**
- FFmpeg message: `[mov,mp4,m4a,3gp,3g2,mj2 @ ...] moov atom not found` and `Error opening input: Invalid data found when processing input`

it means the **MP4 file on disk is invalid**: FFmpeg cannot find the **moov** atom (the metadata that describes the file structure). The file is either:

1. **Incomplete** – download or upload was cut off before the file finished writing.
2. **Corrupt** – disk error or interrupted write left the file in a bad state.
3. **Not actually an MP4** – the path points to an empty file, an HTML error page, or another format mis-saved as `.mp4`.

So the **source file** at the path (e.g. `storage/app/public/media/{asset_id}/{source_id}/...mp4`) is the problem, not FFmpeg or the CDN code.

## What the CDN does now

- **Optimize (faststart)** runs first. If it fails with this error, the source is set to **Optimize: failed** and a **short** error message is stored (e.g. "moov atom not found … Invalid data found when processing input") so the UI stays readable.
- **HLS** is **not** run when faststart has already failed, so you no longer get repeated HLS variant failures for the same bad file.

## What you should do

1. **Check the file on the CDN server**
   - Path from the log, e.g.
     `/home/naraboxt/domains/cdn.naraboxtv.com/public_html/storage/app/public/media/019ce689-94c4-7086-8a6e-c1a2572a85e8/315/MY_DEAD_FRIEND_ZOE__VJ_TONNY_2025_naraboxtv_com.mp4`
   - `ls -la` to see size; try playing it or run:
     ```bash
     ffprobe -v error -show_format "/path/to/file.mp4"
     ```
   - If it’s 0 bytes, very small, or ffprobe says "moov atom not found", the file is bad.

2. **Fix the source**
   - **Re-upload** or **re-import** the asset so a full, valid MP4 is stored.
   - If the file was **fetched from a URL** (e.g. remote_fetch), ensure the fetch completed and that the URL returns a valid MP4. Re-trigger the import/fetch and confirm the file size and duration after.

3. **Then re-run optimization**
   - In Filament: open the media asset → Sources → use **Run re-optimise** (or **Queue for optimization**) for that source so faststart (and then HLS or worker) runs again on the new file.

## Summary

| Log message              | Cause                    | Action                                      |
|--------------------------|--------------------------|---------------------------------------------|
| moov atom not found      | Invalid/incomplete MP4   | Check file on disk; re-upload or re-fetch   |
| Original media file not found | File missing          | Check path and disk; re-import source       |

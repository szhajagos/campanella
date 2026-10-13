# 5. Images

Campanella keeps the images itself: you upload them, and use them in texts
and as the share image. This chapter shows how.

## Uploading in the Image list

In the menu on the left, **Image** opens the list of uploaded images, with
the upload box at the top:

![The list of images with the upload box](../images/en/image-list.png)

- Click the file chooser and choose one or more images, or drag them from
  your computer onto the box. Uploading starts at once, and each image is
  reported.
- Accepted formats: **JPEG, PNG, WebP and GIF**. Under the box you see how
  large a file may be. If your camera's or phone's pictures are larger, the
  limit can be raised in the host's PHP settings (chapter 2, the System page),
  or make them smaller before uploading.
- An image may have at most 25 megapixels (e.g. 6000×4000 pixels).

On upload, Campanella:

- checks that the file really is an image (not just by its extension),
- encodes it again, and on the way **removes its metadata**: e.g. the GPS
  coordinates a phone records, which would tell where the picture was taken,
- makes **smaller copies** of it (320, 640, 1024 and 1600 pixels wide). The
  site always sends the one that fits the visitor's screen, so it loads
  quickly on a phone too.

An image's title is its file name at first. In the list, **no alternative
text** marks the images that have no description yet.

## An image's details

Clicking an image's title opens its edit page:

![Editing an image](../images/en/image-form.png)

- **Title:** how you find the image in the admin; worth changing from the
  file name to something meaningful.
- **Alternative text:** a short description of what the image shows. Screen
  readers read it out to blind and partially sighted visitors, search engines
  learn from it what the image is about, and it shows when the image does not
  load. Describe what the picture shows in one sentence (not "an image").
- On the right: the image, and its details: type, size, its address on the
  site.

## An image in a text

In the editor of articles and pages, the **image** button uploads an image
straight into the text, where the cursor is:

![The editor's image upload](../images/en/editor-image.png)

On the **Upload** tab, click the box and choose the image, or drop it there.
The image is uploaded as in the Image list (it appears there too), and put
into the text. Dragging or pasting an image into the editor uploads it too.

**Give it an alternative text in the text too:** double-click the image in
the editor, fill in **Alternative**, then **Apply**:

![The image's properties in the editor](../images/en/editor-image-properties.png)

Here, **Link** also turns the image into a link. The settings of the
**Advanced** tab (margins, styles) are removed when saving: the site's look
decides where the image goes.

After saving, the article looks like this on the site:

![The article with its image on the site](../images/en/site-article.png)

## The share image

The image shown when someone shares your site on a social site is chosen
under **System → Site settings** (chapter 2). Ideally it is 1200×630 pixels.

## Deleting an image

On an image's edit page, **Delete** first shows **which texts show the
image**. If you delete it anyway, those texts show a broken image (or only
its alternative text) in its place, so take it out of them first, or replace
it. Deleting removes the file and its smaller copies too, and they cannot be
restored.

---

Previous: [4. Menus](04-menus.md) · Next:
[6. Users and your own account](06-users.md) · [Contents](README.md)

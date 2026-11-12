import { Controller } from '@hotwired/stimulus';

/*
 * Sends the picture chosen for an account as soon as it is chosen, shrunk
 * in the browser to a small square cut from its centre, so that a photo
 * of several megabytes is neither refused nor served as it is on every
 * page. When the browser cannot read the image, the file goes as chosen
 * and the server decides.
 *
 * <form data-controller="avatar">
 *     <input type="file" name="avatar" data-avatar-target="input" data-action="change->avatar#send">
 * </form>
 */
const SIDE = 256;

export default class extends Controller {
    static targets = ['input'];

    async send() {
        const [file] = this.inputTarget.files;
        if (!file) {
            return;
        }
        try {
            const image = await createImageBitmap(file);
            const side = Math.min(image.width, image.height);
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = SIDE;
            canvas.getContext('2d').drawImage(image, (image.width - side) / 2, (image.height - side) / 2, side, side, 0, 0, SIDE, SIDE);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', 0.9));
            if (blob) {
                // A browser that cannot write WebP answers with a PNG.
                const extension = blob.type === 'image/webp' ? 'webp' : 'png';
                const files = new DataTransfer();
                files.items.add(new File([blob], `avatar.${extension}`, { type: blob.type }));
                this.inputTarget.files = files.files;
            }
        } catch {
            // Not an image the browser reads: sent as chosen.
        }
        this.element.requestSubmit();
    }
}

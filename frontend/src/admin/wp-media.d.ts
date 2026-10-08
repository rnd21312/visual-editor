/** Minimal typings for the WordPress media modal (wp.media), enqueued by wp_enqueue_media(). */
type WpAttachment = { id: number; url: string; sizes?: { medium?: { url: string } } };

type WpMediaFrame = {
  on: (event: 'select', callback: () => void) => void;
  open: () => void;
  state: () => { get: (key: 'selection') => { toJSON: () => WpAttachment[] } };
};

interface Window {
  wp?: {
    media?: (options: {
      title: string;
      button: { text: string };
      library: { type: string };
      multiple: boolean;
    }) => WpMediaFrame;
  };
}

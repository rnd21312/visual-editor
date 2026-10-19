<?php
/**
 * Front-end libraries the site can switch on (GSAP, Swiper, Alpine, Lenis, React…), pinned to exact versions on
 * jsDelivr / esm.sh, loaded only on the pages that use them. Custom https libraries can be added by administrators.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Libraries {

	private const JSD = 'https://cdn.jsdelivr.net/npm/';

	public const REACT = '18.3.1';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function catalog(): array {
		$gsap = self::JSD . 'gsap@3.13.0/dist/';
		$list = array(
			'gsap'              => array( 'name' => 'GSAP', 'category' => 'motion', 'version' => '3.13.0', 'assets' => array( array( 'script', $gsap . 'gsap.min.js' ) ), 'usage' => 'Global `gsap`. gsap.to(".box", {x: 100, duration: 1}). Element motions set in the editor load GSAP automatically.' ),
			'gsap-scrolltrigger' => array( 'name' => 'GSAP ScrollTrigger', 'category' => 'motion', 'version' => '3.13.0', 'deps' => array( 'gsap' ), 'assets' => array( array( 'script', $gsap . 'ScrollTrigger.min.js' ) ), 'usage' => 'Global `ScrollTrigger` (registered automatically). gsap.to(el, {scrollTrigger: {trigger: el, start: "top 80%", scrub: true}, y: -100}).' ),
			'gsap-splittext'    => array( 'name' => 'GSAP SplitText', 'category' => 'motion', 'version' => '3.13.0', 'deps' => array( 'gsap' ), 'assets' => array( array( 'script', $gsap . 'SplitText.min.js' ) ), 'usage' => 'Global `SplitText`. const s = SplitText.create("h1", {type: "words,chars"}); gsap.from(s.chars, {y: 40, opacity: 0, stagger: 0.03}).' ),
			'gsap-scrollsmoother' => array( 'name' => 'GSAP ScrollSmoother', 'category' => 'motion', 'version' => '3.13.0', 'deps' => array( 'gsap', 'gsap-scrolltrigger' ), 'assets' => array( array( 'script', $gsap . 'ScrollSmoother.min.js' ) ), 'usage' => 'Needs #smooth-wrapper > #smooth-content around the page.' ),
			'gsap-flip'         => array( 'name' => 'GSAP Flip', 'category' => 'motion', 'version' => '3.13.0', 'deps' => array( 'gsap' ), 'assets' => array( array( 'script', $gsap . 'Flip.min.js' ) ), 'usage' => 'Global `Flip` for layout transitions.' ),
			'gsap-draggable'    => array( 'name' => 'GSAP Draggable', 'category' => 'motion', 'version' => '3.13.0', 'deps' => array( 'gsap' ), 'assets' => array( array( 'script', $gsap . 'Draggable.min.js' ) ), 'usage' => 'Global `Draggable`. Draggable.create(".card", {type: "x,y"}).' ),
			'gsap-motionpath'   => array( 'name' => 'GSAP MotionPath', 'category' => 'motion', 'version' => '3.13.0', 'deps' => array( 'gsap' ), 'assets' => array( array( 'script', $gsap . 'MotionPathPlugin.min.js' ) ), 'usage' => 'Animate along SVG paths.' ),
			'lenis'             => array( 'name' => 'Lenis smooth scroll', 'category' => 'motion', 'version' => '1.1.20', 'assets' => array( array( 'script', self::JSD . 'lenis@1.1.20/dist/lenis.min.js' ), array( 'style', self::JSD . 'lenis@1.1.20/dist/lenis.css' ) ), 'init' => 'window.__sveLenis=new Lenis({autoRaf:true});', 'usage' => 'Smooth scrolling for the whole page, starts automatically.' ),
			'aos'               => array( 'name' => 'AOS (animate on scroll)', 'category' => 'motion', 'version' => '2.3.4', 'assets' => array( array( 'script', self::JSD . 'aos@2.3.4/dist/aos.js' ), array( 'style', self::JSD . 'aos@2.3.4/dist/aos.css' ) ), 'init' => 'AOS.init({once:true});', 'usage' => 'Add data-aos="fade-up" to elements.' ),
			'animate-css'       => array( 'name' => 'Animate.css', 'category' => 'motion', 'version' => '4.1.1', 'assets' => array( array( 'style', self::JSD . 'animate.css@4.1.1/animate.min.css' ) ), 'usage' => 'class="animate__animated animate__fadeInUp".' ),
			'motion'            => array( 'name' => 'Motion (Framer Motion for vanilla JS)', 'category' => 'motion', 'version' => '11.18.2', 'assets' => array( array( 'script', self::JSD . 'motion@11.18.2/dist/motion.js' ) ), 'usage' => 'Global `Motion`: Motion.animate(".box", {rotate: 360}, {duration: 1}).' ),
			'lottie'            => array( 'name' => 'Lottie', 'category' => 'motion', 'version' => '5.12.2', 'assets' => array( array( 'script', self::JSD . 'lottie-web@5.12.2/build/player/lottie.min.js' ) ), 'usage' => 'lottie.loadAnimation({container: el, path: "https://…/anim.json", loop: true, autoplay: true}).' ),
			'swiper'            => array( 'name' => 'Swiper (sliders, carousels)', 'category' => 'ui', 'version' => '11.2.6', 'assets' => array( array( 'script', self::JSD . 'swiper@11.2.6/swiper-bundle.min.js' ), array( 'style', self::JSD . 'swiper@11.2.6/swiper-bundle.min.css' ) ), 'init' => 'document.querySelectorAll(".swiper[data-swiper]").forEach(function(el){try{new Swiper(el,JSON.parse(el.getAttribute("data-swiper")||"{}"))}catch(e){new Swiper(el,{})}});', 'usage' => '<div class="swiper" data-swiper=\'{"slidesPerView":1.2,"spaceBetween":16,"pagination":{"el":".swiper-pagination"}}\'><div class="swiper-wrapper"><div class="swiper-slide">…</div></div><div class="swiper-pagination"></div></div> — starts automatically.' ),
			'glightbox'         => array( 'name' => 'GLightbox', 'category' => 'ui', 'version' => '3.3.1', 'assets' => array( array( 'script', self::JSD . 'glightbox@3.3.1/dist/js/glightbox.min.js' ), array( 'style', self::JSD . 'glightbox@3.3.1/dist/css/glightbox.min.css' ) ), 'init' => 'GLightbox({selector:".glightbox"});', 'usage' => '<a class="glightbox" href="big.jpg"><img src="thumb.jpg"></a>.' ),
			'alpine'            => array( 'name' => 'Alpine.js', 'category' => 'interactivity', 'version' => '3.14.9', 'assets' => array( array( 'script', self::JSD . 'alpinejs@3.14.9/dist/cdn.min.js' ) ), 'usage' => 'Declarative behaviour in markup: <div x-data="{open:false}"><button @click="open=!open">Menu</button><nav x-show="open">…</nav></div>. Note: x-/@ attributes need a trusted (admin) author.' ),
			'htmx'              => array( 'name' => 'htmx', 'category' => 'interactivity', 'version' => '2.0.4', 'assets' => array( array( 'script', self::JSD . 'htmx.org@2.0.4/dist/htmx.min.js' ) ), 'usage' => 'hx-get / hx-post attributes.' ),
			'chartjs'           => array( 'name' => 'Chart.js', 'category' => 'data', 'version' => '4.4.9', 'assets' => array( array( 'script', self::JSD . 'chart.js@4.4.9/dist/chart.umd.min.js' ) ), 'usage' => 'new Chart(canvas, {type: "bar", data: {…}}).' ),
			'countup'           => array( 'name' => 'CountUp.js', 'category' => 'ui', 'version' => '2.8.0', 'assets' => array( array( 'script', self::JSD . 'countup.js@2.8.0/dist/countUp.umd.js' ) ), 'init' => 'document.querySelectorAll("[data-countup]").forEach(function(el){var c=new countUp.CountUp(el,parseFloat(el.getAttribute("data-countup"))||0,{enableScrollSpy:true});c.start&&c.start();});', 'usage' => '<span data-countup="1250">0</span> counts up when visible.' ),
			'typed'             => array( 'name' => 'Typed.js', 'category' => 'ui', 'version' => '2.1.0', 'assets' => array( array( 'script', self::JSD . 'typed.js@2.1.0/dist/typed.umd.js' ) ), 'init' => 'document.querySelectorAll("[data-typed]").forEach(function(el){new Typed(el,{strings:(el.getAttribute("data-typed")||"").split("|"),typeSpeed:50,backSpeed:30,loop:true});});', 'usage' => '<span data-typed="Phuket|Bali|Dubai"></span>.' ),
			'vanilla-tilt'      => array( 'name' => 'Vanilla Tilt', 'category' => 'ui', 'version' => '1.8.1', 'assets' => array( array( 'script', self::JSD . 'vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js' ) ), 'usage' => 'Add data-tilt to an element.' ),
			'lucide'            => array( 'name' => 'Lucide icons', 'category' => 'icons', 'version' => '0.468.0', 'assets' => array( array( 'script', self::JSD . 'lucide@0.468.0/dist/umd/lucide.min.js' ) ), 'init' => 'lucide.createIcons();', 'usage' => '<i data-lucide="plane"></i> (the editor can also insert icons as inline SVG, which needs no library).' ),
			'fontawesome'       => array( 'name' => 'Font Awesome Free', 'category' => 'icons', 'version' => '6.7.2', 'assets' => array( array( 'style', self::JSD . '@fortawesome/fontawesome-free@6.7.2/css/all.min.css' ) ), 'usage' => '<i class="fa-solid fa-plane"></i>.' ),
			'bootstrap-icons'   => array( 'name' => 'Bootstrap Icons', 'category' => 'icons', 'version' => '1.11.3', 'assets' => array( array( 'style', self::JSD . 'bootstrap-icons@1.11.3/font/bootstrap-icons.min.css' ) ), 'usage' => '<i class="bi bi-airplane"></i>.' ),
			'three'             => array( 'name' => 'Three.js', 'category' => '3d', 'version' => '0.170.0', 'usage' => 'ES module: import * as THREE from "https://cdn.jsdelivr.net/npm/three@0.170.0/build/three.module.min.js" inside a module script, or `import * as THREE from "three"` inside a React island (resolved through esm.sh).' ),
			'react'             => array( 'name' => 'React ' . self::REACT, 'category' => 'react', 'version' => self::REACT, 'usage' => 'Loaded automatically (from esm.sh) on pages with React islands — components of kind "react". Any npm package can be imported in an island: it is fetched from esm.sh with React shared.' ),
		);

		$out = array();
		foreach ( $list as $id => $lib ) {
			$assets = array();
			foreach ( (array) ( $lib['assets'] ?? array() ) as $asset ) {
				$assets[] = array( 'type' => $asset[0], 'url' => $asset[1] );
			}
			$out[ $id ] = array(
				'id'       => $id,
				'name'     => $lib['name'],
				'category' => $lib['category'],
				'version'  => $lib['version'],
				'deps'     => $lib['deps'] ?? array(),
				'assets'   => $assets,
				'modules'  => $lib['modules'] ?? array(),
				'init'     => $lib['init'] ?? '',
				'usage'    => $lib['usage'],
			);
		}

		return $out;
	}

	/**
	 * Import-map entries of React (bare specifiers used by islands and esm.sh packages built with external=react).
	 *
	 * @return array<string, string>
	 */
	public static function react_modules(): array {
		$v = self::REACT;

		return array(
			'react'             => "https://esm.sh/react@{$v}",
			'react/'            => "https://esm.sh/react@{$v}/",
			'react-dom'         => "https://esm.sh/react-dom@{$v}?external=react",
			'react-dom/client'  => "https://esm.sh/react-dom@{$v}/client?external=react",
			'react/jsx-runtime' => "https://esm.sh/react@{$v}/jsx-runtime",
		);
	}

	/**
	 * Libraries enabled for the page being rendered, with their dependencies, in load order.
	 *
	 * @param array<string, mixed> $doc   Document.
	 * @param array<int, string>   $extra Library ids the page needs anyway (motions → gsap…).
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_page( array $doc, string $page, string $tpl, array $extra = array() ): array {
		$catalog = self::catalog();
		$wanted  = array();
		foreach ( (array) $doc['libraries'] as $lib ) {
			if ( empty( $lib['enabled'] ) ) {
				continue;
			}
			$applies = match ( (string) $lib['scope'] ) {
				'page'     => in_array( $page, (array) $lib['pages'], true ),
				'template' => in_array( $tpl, (array) $lib['tpls'], true ),
				default    => true,
			};
			if ( $applies ) {
				$wanted[ $lib['id'] ] = $lib;
			}
		}
		foreach ( $extra as $id ) {
			if ( isset( $catalog[ $id ] ) && ! isset( $wanted[ $id ] ) ) {
				$wanted[ $id ] = array( 'id' => $id );
			}
		}

		$ordered = array();
		$visit   = static function ( string $id ) use ( &$visit, &$ordered, $catalog, $wanted ): void {
			if ( isset( $ordered[ $id ] ) ) {
				return;
			}
			$entry = $catalog[ $id ] ?? null;
			if ( null === $entry ) {
				$custom = $wanted[ $id ] ?? null;
				if ( ! is_array( $custom ) || empty( $custom['url'] ) ) {
					return;
				}
				$entry = array(
					'id'      => $id,
					'deps'    => array(),
					'assets'  => array( array( 'type' => $custom['type'] ?? 'script', 'url' => $custom['url'] ) ),
					'modules' => array(),
					'init'    => '',
				);
			}
			$ordered[ $id ] = true; // Marks before visiting deps: cycles cannot loop.
			foreach ( $entry['deps'] as $dep ) {
				$visit( $dep );
			}
			unset( $ordered[ $id ] );
			$ordered[ $id ] = $entry;
		};
		foreach ( array_keys( $wanted ) as $id ) {
			$visit( (string) $id );
		}

		return array_values( array_filter( $ordered, 'is_array' ) );
	}

	/**
	 * Registers the libraries of the current page with WordPress (deduplicated, deferred, in the footer).
	 *
	 * @param array<int, array<string, mixed>> $libs Result of for_page().
	 */
	public static function enqueue( array $libs ): void {
		$previous = array();
		foreach ( $libs as $lib ) {
			$handles = array();
			foreach ( (array) $lib['assets'] as $i => $asset ) {
				$handle = 'sve-lib-' . $lib['id'] . ( $i > 0 ? '-' . $i : '' );
				if ( 'style' === $asset['type'] ) {
					wp_enqueue_style( $handle, $asset['url'], array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the URL pins the version.
					continue;
				}
				if ( 'module' === $asset['type'] ) {
					wp_enqueue_script_module( $handle, $asset['url'], array(), null );
					continue;
				}
				wp_enqueue_script( $handle, $asset['url'], $previous, null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				$handles[] = $handle;
			}
			foreach ( (array) $lib['modules'] as $specifier => $url ) {
				wp_register_script_module( (string) $specifier, (string) $url, array(), null );
			}
			if ( '' !== (string) $lib['init'] && array() !== $handles ) {
				wp_add_inline_script( end( $handles ), 'document.addEventListener("DOMContentLoaded",function(){try{' . $lib['init'] . '}catch(e){console.warn("[sve] ' . esc_js( $lib['id'] ) . '",e)}});' );
			}
			if ( 'gsap-scrolltrigger' === $lib['id'] && array() !== $handles ) {
				wp_add_inline_script( end( $handles ), 'gsap.registerPlugin(ScrollTrigger);' );
			}
			if ( array() !== $handles ) {
				$previous = array( end( $handles ) );
			}
		}
	}
}

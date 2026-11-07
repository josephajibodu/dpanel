import { SVGAttributes } from 'react';

/**
 * The flitops mark: three stacked server bars that step down to form an "F",
 * each with a small status light cut out of it.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="6 6 20 22" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M8 6h16a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zM20.75 9a1.25 1.25 0 1 0 2.5 0a1.25 1.25 0 1 0-2.5 0zM8 14h10a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2zM14.75 17a1.25 1.25 0 1 0 2.5 0a1.25 1.25 0 1 0-2.5 0zM8 22h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2z"
            />
        </svg>
    );
}

import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 28" xmlns="http://www.w3.org/2000/svg">
            <circle cx="11" cy="18" r="10" />
            <circle cx="22" cy="12" r="12" />
            <circle cx="30" cy="19" r="9" />
            <rect x="11" y="18" width="19" height="10" />
        </svg>
    );
}

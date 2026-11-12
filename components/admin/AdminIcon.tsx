import React from 'react';
import Image from 'next/image';

interface AdminIconProps {
  name: string;
  size?: number;
  width?: number;
  height?: number;
  className?: string;
  style?: React.CSSProperties;
  alt?: string;
}

export const AdminIcon: React.FC<AdminIconProps> = ({
  name,
  size = 16,
  width,
  height,
  className = '',
  style,
  alt = '',
}) => {
  const w = width ?? size;
  const h = height ?? size;

  // SVG icons are served as they are (nothing to resize).
  return (
    <Image
      src={`/admin/${name}.svg`}
      alt={alt}
      width={w}
      height={h}
      unoptimized
      className={`block shrink-0 ${className}`}
      style={{ width: w, height: h, ...style }}
    />
  );
};

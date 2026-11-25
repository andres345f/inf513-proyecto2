import './Insignia.css'

/** Etiqueta pequeña de estado. tono: exito | error | aviso | info */
export default function Insignia({ tono = 'info', children }) {
  return <span className={`insignia insignia--${tono}`}>{children}</span>
}

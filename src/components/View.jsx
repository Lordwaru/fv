import React, { Component, createRef } from "react";
import { sourceMap } from "../common/SourceMap";
// @ts-ignore
// import Twitch from "twitch-embed";

import { ViewControls } from "./ViewControls";

export class View extends Component {
    constructor(props) {
        super(props);
        this.state = { width: 100, pixelWidth: 0 };

        // Bind event callbacks
        this.resize = this.resize.bind(this);
        this.endResize = this.endResize.bind(this);

        // Avoid re-renders
        this.element = createRef();
    }

    componentDidMount() {
        const width = this.props.data.width || 100;
        const pixelWidth = this.getPixelWidth();
        this.setState({ width, pixelWidth });
        this.props.trackViews();
    }

    componentDidUpdate(previousProps) {
        if(previousProps.data.host !== this.props.data.host || previousProps.data.embed_id !== this.props.data.embed_id) {
            this.props.trackViews();
        }
    }

    getSrc() {
        const { s } = this.getElapsedTime();
        return sourceMap[this.props.data.host].getSrc(this.props.data.embed_id, s);
    }

    getElapsedTime() {
        const start = new Date(this.props.sessionStart);
        const now = new Date();
        const ms = Number(now) - Number(start);
        const s = Math.floor(ms / 1000);
        const m = Math.floor(s / 60);
        const h = Math.floor(m / 60);
        return { ms, s, m, h };
    }

    getPixelWidth() {
        return Number(this.element.current.offsetWidth);
    }

    resize(width) {
        const w = Math.floor(((this.state.pixelWidth + width) / this.props.getMaxWidth()) * 100);
        this.setState({ width: w < 100 ? w : 100 });
    }

    endResize() {
        const pixelWidth = this.getPixelWidth();
        this.setState({ pixelWidth });
    }

    render() {
        const aspectRatio = { "--aspect-ratio": "16/9" };
        return (
            <div className="view" ref={this.element} style={{ width: `${this.state.width}%` }}>
                <div className="stream" style={{ ...aspectRatio }}>
                    {this.getSrc() ? (
                        <iframe className="player" title="title" src={this.getSrc()} allow="fullscreen" />
                    ) : (
                        <div className="player" />
                    )}
                </div>
                <ViewControls
                    active={this.props.active}
                    id={this.props.id}
                    host={this.props.data.host}
                    embed_id={this.props.data.embed_id}
                    size={{ width: this.state.width, pixelWidth: this.state.pixelWidth }}
                    resize={this.resize}
                    endResize={this.endResize}
                />
                <div>
                {/* <pre>
                    {JSON.stringify(this.props, undefined, 2)}
                </pre> */}
                </div>
            </div>
        );
    }
}
